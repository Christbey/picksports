<?php

namespace App\Console\Commands\WCBB;

use App\Models\CanonicalPrediction;
use App\Models\PredictionFeatureSnapshot;
use App\Models\WCBB\Game;
use App\Services\Predictions\CanonicalShadowInputs;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ComparePregamePredictionsCommand extends Command
{
    protected $signature = 'wcbb:compare-pregame-predictions
        {--season= : WCBB season to compare}
        {--from-date= : Earliest game date to include}
        {--release-version= : Canonical release version to compare}';

    protected $description = 'Compare paired WCBB predictions recorded before tipoff without regenerating predictions';

    public function handle(CanonicalShadowInputs $inputs): int
    {
        $season = (int) ($this->option('season') ?: config('wcbb.season.default'));
        $cohorts = [];
        $versions = [];
        $coverage = ['finished_games' => 0, 'canonical_only' => 0, 'legacy_only' => 0, 'neither' => 0, 'paired' => 0];

        Game::query()->with('sportEvent')
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereColumn('home_score', '!=', 'away_score')
            ->when($this->option('from-date'), fn ($query, $date) => $query->where('game_date', '>=', $date))
            ->chunkById(200, function (Collection $games) use ($inputs, &$cohorts, &$versions, &$coverage): void {
                $legacyRows = PredictionFeatureSnapshot::query()
                    ->where('sport', 'wcbb')->where('prediction_table', 'wcbb_predictions')
                    ->whereIn('game_id', $games->modelKeys())
                    ->where('pregame_safe', true)
                    ->where('availability_status', 'observed_pregame')
                    ->whereColumn('generated_at', '<', 'game_start_at')
                    ->whereColumn('features_available_at', '<=', 'generated_at')
                    ->where('lineage_metadata->run_type', 'prediction')
                    ->orderByDesc('generated_at')->orderByDesc('id')->get()->groupBy('game_id');

                $canonicalRows = CanonicalPrediction::query()
                    ->with(['markets', 'calculationRun.inputSnapshot', 'calculationRun.release', 'sportEvent.wcbbGame'])
                    ->where('sport', 'wcbb')->where('phase', 'pregame')
                    ->whereIn('publication_state', ['published', 'superseded'])
                    ->whereIn('sport_event_id', $games->pluck('sport_event_id')->filter())
                    ->when($this->option('release-version'), fn ($query, $version) => $query
                        ->whereHas('calculationRun.release', fn ($release) => $release->where('semantic_version', $version)))
                    ->orderByDesc('published_at')->orderByDesc('revision')->get()->groupBy('sport_event_id');

                foreach ($games as $game) {
                    $coverage['finished_games']++;
                    $start = $game->sportEvent?->starts_at;
                    if ($start === null) {
                        $coverage['neither']++;

                        continue;
                    }

                    $legacy = $legacyRows->get($game->id, collect())->first(fn ($row) => $row->generated_at->lt($start)
                        && $this->validOutputs($row->outputs ?? []));
                    $canonical = null;
                    $forecast = null;
                    foreach ($canonicalRows->get($game->sport_event_id, collect()) as $revision) {
                        if (! $revision->published_at?->lt($start)) {
                            continue;
                        }
                        $candidate = $inputs->fromPrediction($revision);
                        if ($candidate !== null && $this->validOutputs($candidate->outputs)) {
                            $canonical = $revision;
                            $forecast = $candidate;
                            break;
                        }
                    }

                    if ($legacy === null || $forecast === null) {
                        $coverage[$legacy !== null ? 'legacy_only' : ($forecast !== null ? 'canonical_only' : 'neither')]++;

                        continue;
                    }

                    $coverage['paired']++;
                    $version = $canonical->calculationRun->release->semantic_version;
                    $legacyVersion = $legacy->model_version.' / '.$legacy->blend_version;
                    $versions[$version][$legacyVersion] = ($versions[$version][$legacyVersion] ?? 0) + 1;
                    $snapshot = $canonical->calculationRun->inputSnapshot;
                    $minGames = (int) data_get($canonical->calculationRun->release->configuration, 'spread.minimum_metric_games', 8);
                    $early = collect(['home', 'away'])->contains(function ($side) use ($snapshot, $game, $minGames): bool {
                        $metrics = data_get($snapshot->inputs, $side.'.metrics', []);

                        return (int) data_get($metrics, 'record_season') !== (int) $game->season
                            || (int) data_get($metrics, 'wins', 0) + (int) data_get($metrics, 'losses', 0) < $minGames;
                    });
                    $labels = ['all'];
                    if (data_get($snapshot->inputs, 'event.neutral_site') === true) {
                        $labels[] = 'neutral_site';
                    }
                    if ($early) {
                        $labels[] = 'limited_current_season_sample';
                    }
                    if (! is_numeric(data_get($snapshot->inputs, 'pregame_market.consensus.spread.line'))) {
                        $labels[] = 'no_frozen_canonical_spread';
                    }

                    $margin = (int) $game->home_score - (int) $game->away_score;
                    $total = (int) $game->home_score + (int) $game->away_score;
                    foreach ($labels as $label) {
                        foreach (['legacy' => $legacy->outputs, 'canonical' => $forecast->outputs] as $source => $output) {
                            $stats = $cohorts[$label][$source] ?? ['games' => 0, 'spread_mae' => 0, 'total_mae' => 0, 'brier_score' => 0];
                            $stats['games']++;
                            $stats['spread_mae'] += abs($margin - (float) $output['predicted_spread']);
                            $stats['total_mae'] += abs($total - (float) $output['predicted_total']);
                            $stats['brier_score'] += ((float) $output['win_probability'] - (int) ($margin > 0)) ** 2;
                            $cohorts[$label][$source] = $stats;
                        }
                    }
                }
            });

        foreach ($cohorts as &$sources) {
            foreach ($sources as &$stats) {
                foreach (['spread_mae', 'total_mae', 'brier_score'] as $metric) {
                    $stats[$metric] = round($stats[$metric] / $stats['games'], 4);
                }
            }
            unset($stats);
        }
        unset($sources);

        $this->line(json_encode([
            'season' => $season,
            'coverage' => $coverage,
            'paired_versions' => $versions,
            'cohorts' => $cohorts,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function validOutputs(array $outputs): bool
    {
        $probability = $outputs['win_probability'] ?? null;

        return is_numeric($outputs['predicted_spread'] ?? null)
            && is_numeric($outputs['predicted_total'] ?? null)
            && is_numeric($probability)
            && (float) $probability >= 0 && (float) $probability <= 1;
    }
}
