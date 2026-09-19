<?php

namespace App\Services\CFB\Signals;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CanonicalPrediction;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Uses only predictions and outcomes already observed at capture; never reconstructs old inputs. */
class CfbFootballSignalEvidence
{
    public static function baselineHash(array $configuration): string
    {
        // Independent-result forecasts return before applying these FPI-baseline weights.
        unset($configuration['football_signals'], $configuration['independent_result_rating']);

        return app(CanonicalPayloadHasher::class)->hash($configuration);
    }

    public static function catalogHash(array $catalog): string
    {
        return app(CanonicalPayloadHasher::class)->hash($catalog);
    }

    public function build(CarbonImmutable $asOf, array $configuration): array
    {
        $catalog = data_get($configuration, 'football_signals.catalog') ?? CfbFootballSignalCatalog::all();
        $baselineHash = self::baselineHash($configuration);
        $observations = $seen = $sourceIds = [];
        $latest = null;
        $replayed = [];
        $jointRows = [];
        $replay = data_get($configuration, 'football_signals.training_policy') === 'replay_frozen_baseline_v1';
        $predictions = CanonicalPrediction::query()->where('sport', 'cfb')->where('phase', 'pregame')
            ->whereIn('publication_state', ['published', 'superseded'])
            ->where('generated_at', '<', $asOf)->where('published_at', '<', $asOf)
            ->where('created_at', '<=', $asOf)->where('generated_at', '>=', $asOf->subYears(3))
            ->whereHas('sportEvent.cfbGame', fn ($q) => $q->where('status', 'STATUS_FINAL')->where('updated_at', '<=', $asOf))
            ->with(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot', 'sportEvent.cfbGame'])
            ->orderByDesc('generated_at')->orderByDesc('id')->lazy(50);
        foreach ($predictions as $prediction) {
            $game = $prediction->sportEvent?->cfbGame;
            $kickoff = $prediction->sportEvent?->starts_at;
            $snapshot = $prediction->calculationRun?->inputSnapshot;
            $release = $prediction->calculationRun?->release;
            if (! $game || isset($seen[$game->id]) || ! $kickoff || ! $snapshot || ! $release
                || ($replay && $snapshot->schema_version !== 'cfb-pregame-v1')
                || $snapshot->pregame_safety_status !== 'verified' || ! $snapshot->captured_at
                || $snapshot->captured_at->gte($kickoff) || $snapshot->captured_at->gt($prediction->generated_at)
                || ! $prediction->published_at || $prediction->published_at->gte($kickoff)
                || $snapshot->created_at->gte($kickoff) || $prediction->created_at->gte($kickoff)
                || $snapshot->created_at->gt($asOf) || $prediction->generated_at->gte($kickoff)
                || $kickoff->gte($asOf) || $game->created_at->gt($asOf)
                || ($snapshot->latest_source_available_at && $snapshot->latest_source_available_at->gt($snapshot->captured_at))
                || (! $replay && self::baselineHash($release->configuration) !== $baselineHash)
                || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)
                || $game->home_score < 0 || $game->away_score < 0 || $game->home_score == $game->away_score) {
                continue;
            }
            $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
            $total = $prediction->markets->first(fn ($m) => $m->market_type === 'total');
            if (! is_numeric($spread?->projected_line) || ! is_numeric($total?->projected_line)) {
                continue;
            }
            $seen[$game->id] = true;
            $sourceIds[] = $prediction->id;
            $observed = $game->updated_at->toImmutable();
            $latest = $latest === null || $observed->gt($latest) ? $observed : $latest;
            $baseMargin = data_get($prediction->calculationRun->diagnostics, 'football_signals.base_home_margin', -(float) $spread->projected_line);
            $baseTotal = data_get($prediction->calculationRun->diagnostics, 'football_signals.base_total', (float) $total->projected_line);
            if ($replay) {
                // Recalculate the target baseline on the original immutable inputs. Never
                // substitute today's team ratings, and never relabel this as a live old forecast.
                $baselineConfiguration = $configuration;
                $baselineConfiguration['football_signals']['enabled'] = false;
                $baselineRelease = new CalculationReleaseData('signal-training-replay', 'cfb', 'pregame',
                    'cfb-pregame-rules', 'rules', 'training-replay', 'frozen-input-replay', $baselineHash,
                    $snapshot->schema_version, $baselineConfiguration);
                $frozen = new EventInputSnapshotData($snapshot->schema_version, $snapshot->inputs,
                    $snapshot->captured_at, $snapshot->cutoff_at, $snapshot->latest_source_available_at,
                    $snapshot->source_timestamps ?? [], $snapshot->pregame_safety_status);
                $output = app(CfbCalculator::class)->calculate($frozen, $baselineRelease);
                $baseMargin = -collect($output->markets)->first(fn ($m) => $m->marketType === 'spread')->projectedLine;
                $baseTotal = collect($output->markets)->first(fn ($m) => $m->marketType === 'total')->projectedLine;
                $replayed[] = $prediction->id;
            }
            $residuals = ['spread' => $game->home_score - $game->away_score - $baseMargin,
                'total' => $game->home_score + $game->away_score - $baseTotal];
            if (isset($snapshot->inputs['historical_signals'])
                && is_numeric(data_get($snapshot->inputs, 'home.metrics.fpi'))
                && is_numeric(data_get($snapshot->inputs, 'away.metrics.fpi'))) {
                $jointRows[$game->id] = ['game_id' => $game->id, 'starts_at' => $kickoff->toIso8601String(),
                    'residuals' => $residuals, 'features' => CfbFootballSignalJointModel::features($snapshot->inputs, $configuration['football_signals'])];
            }
            foreach (['home', 'away'] as $side) {
                $features = CfbFootballSignalCatalog::features($snapshot->inputs, $side, data_get($configuration, 'football_signals.feature_policy', 'observed_only'));
                foreach ($catalog as $id => $definition) {
                    $market = $definition['market'];
                    if (! isset($residuals[$market]) || CfbFootballSignalCatalog::evaluate($definition, $features) !== true) {
                        continue;
                    }
                    // Same game seen from both teams is still one independent observation.
                    // Opposed spread activations cancel rather than becoming two training samples.
                    $value = $residuals[$market] * ($market === 'spread' && $side === 'away' ? -1 : 1);
                    $existing = $observations[$id][$game->id]['residual'] ?? null;
                    $observations[$id][$game->id] = ['game_id' => $game->id, 'starts_at' => $kickoff->toIso8601String(),
                        'residual' => $existing === null ? $value : ($existing + $value) / 2];
                }
            }
        }
        $historical = null;
        if (data_get($configuration, 'football_signals.historical_training', false)) {
            $artifact = Cache::get(CfbFootballSignalHistoricalTrainer::key($configuration));
            if (is_array($artifact) && ($artifact['baseline_hash'] ?? null) === $baselineHash
                && isset($artifact['available_at'], $artifact['as_of'])
                && CarbonImmutable::parse($artifact['available_at'])->lte($asOf)
                && CarbonImmutable::parse($artifact['as_of'])->lte($asOf)) {
                foreach ($artifact['observations'] as $id => $rows) {
                    foreach ($rows as $gameId => $row) {
                        // Original frozen support takes precedence for the same rule/game.
                        $observations[$id][$gameId] ??= $row;
                    }
                }
                $jointRows += $artifact['joint_observations'] ?? [];
                $historical = array_diff_key($artifact, ['observations' => true, 'joint_observations' => true]);
                $available = CarbonImmutable::parse($artifact['available_at']);
                $latest = $latest === null || $available->gt($latest) ? $available : $latest;
            }
        }
        $fits = [];
        foreach ($catalog as $id => $definition) {
            $fits[$id] = app(CfbFootballSignalModel::class)->fit(array_values($observations[$id] ?? []));
        }

        $joint = [];
        if (data_get($configuration, 'football_signals.weighting') === 'joint_ridge_v1') {
            foreach (['spread', 'total'] as $market) {
                $joint[$market] = app(CfbFootballSignalJointModel::class)->fit(array_values($jointRows), $market, (float) data_get($configuration, 'football_signals.maximum_'.$market.'_adjustment'));
            }
        }

        return ['joint_models' => $joint, 'version' => CfbFootballSignalModel::VERSION, 'catalog_hash' => self::catalogHash($catalog),
            'feature_policy' => data_get($configuration, 'football_signals.feature_policy', 'observed_only'),
            'training_policy' => data_get($configuration, 'football_signals.training_policy', 'same_release_frozen_predictions'),
            'replayed_prediction_ids' => $replayed, 'historical_training' => $historical,
            'baseline_hash' => $baselineHash, 'as_of' => $asOf->toIso8601String(),
            'latest_source_observed_at' => $latest?->toIso8601String(), 'prediction_ids' => $sourceIds,
            'status' => $historical ? 'retrospective_and_frozen_outcomes_evaluated' : ($sourceIds ? 'frozen_outcomes_evaluated' : 'no_eligible_frozen_outcomes'), 'signals' => $fits];
    }
}
