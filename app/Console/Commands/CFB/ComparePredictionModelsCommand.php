<?php

namespace App\Console\Commands\CFB;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ComparePredictionModelsCommand extends Command
{
    protected $signature = 'cfb:compare-prediction-models {--season=} {--from-week=1} {--to-week=2}
        {--include-legacy-comparison : Include the historical mutable-row comparison flag}';

    protected $description = 'Read-only paired comparison of evaluated pregame forecasts and candidate replay on frozen inputs';

    public function handle(CfbCalculator $calculator, CfbCalculationReleaseDefinition $definition): int
    {
        $values = ['season' => $this->option('season'), 'from' => $this->option('from-week'), 'to' => $this->option('to-week')];
        if (Validator::make($values, ['season' => 'required|integer|min:2000|max:2100',
            'from' => 'required|integer|min:0|max:20', 'to' => 'required|integer|gte:from|max:20'])->fails()) {
            $this->error('Supply a season and an ordered week range.');

            return self::FAILURE;
        }
        $includeLegacy = (bool) $this->option('include-legacy-comparison')
            || (! config('prediction_lifecycle.canonical_pipeline.cfb') && ! config('prediction_lifecycle.canonical_reads.cfb'));
        $games = Game::with($includeLegacy ? ['sportEvent', 'prediction'] : ['sportEvent'])->where('season', $values['season'])
            ->whereBetween('week', [$values['from'], $values['to']])->where('status', 'STATUS_FINAL')->get();
        $config = $definition->configuration();
        $release = new CalculationReleaseData('offline-candidate', 'cfb', 'pregame', $definition->calculatorName(), 'rules',
            $definition->semanticVersion(), 'working-tree', hash('sha256', json_encode($config)), $definition->inputSchemaVersion(), $config);
        $rows = [];
        $excluded = [];
        foreach ($games->chunk(25) as $batch) {
            $forecasts = $this->forecasts($batch);
            foreach ($batch as $game) {
                if (! $game->sportEvent?->starts_at) {
                    $excluded[$game->id] = 'missing_event_start';

                    continue;
                }
                $prediction = $forecasts->get($game->sport_event_id);
                $snapshot = $prediction?->calculationRun?->inputSnapshot;
                $evaluation = $prediction?->latestEvaluation;
                if (! $snapshot || ! $evaluation || $snapshot->pregame_safety_status !== 'verified'
                    || $snapshot->captured_at->gte($game->sportEvent->starts_at)
                    || $snapshot->latest_source_available_at?->gte($game->sportEvent->starts_at)) {
                    $excluded[$game->id] = 'missing_safe_evaluated_snapshot';

                    continue;
                }
                $output = $calculator->calculate(new EventInputSnapshotData($snapshot->schema_version, $snapshot->inputs,
                    $snapshot->captured_at, $snapshot->cutoff_at), $release);
                $markets = collect($output->markets);
                $probability = $markets->first(fn ($m) => $m->marketType === 'moneyline' && $m->selection === 'home')->probability;
                $margin = -$markets->first(fn ($m) => $m->marketType === 'spread')->projectedLine;
                $total = $markets->first(fn ($m) => $m->marketType === 'total')->projectedLine;
                $actualMargin = (float) $evaluation->actuals['home_margin'];
                $actualTotal = (float) $evaluation->actuals['total_points'];
                $actualHomeWin = $actualMargin > 0 ? 1 : 0;
                $rows[] = ['game_id' => $game->id, 'week' => $game->week, 'prediction_id' => $prediction->public_id,
                    'snapshot_id' => $snapshot->public_id, 'official' => $evaluation->errors,
                    'candidate' => ['winner_correct' => ($probability >= 0.5) === ($actualHomeWin === 1),
                        'spread_absolute_error' => abs($actualMargin - $margin),
                        'total_absolute_error' => abs($actualTotal - $total), 'brier_score' => ($probability - $actualHomeWin) ** 2],
                    'legacy_comparable' => $includeLegacy ? ($game->prediction?->updated_at?->lt($game->sportEvent->starts_at) ?? false) : null,
                    'candidate_input_quality' => $output->metadata['input_quality'] ?? null];
            }
        }
        $summaries = [];
        foreach (['official', 'candidate'] as $model) {
            $samples = collect($rows)->pluck($model);
            $summaries[$model] = ['games' => $samples->count(), 'wins' => $samples->where('winner_correct', true)->count(),
                'losses' => $samples->where('winner_correct', false)->count(), 'pushes' => 0];
            foreach (['spread_absolute_error', 'total_absolute_error', 'brier_score'] as $metric) {
                $summaries[$model][$metric] = $samples->isEmpty() ? null : round($samples->avg($metric), 4);
            }
        }
        $this->line(json_encode(['candidate_version' => $definition->semanticVersion(),
            'legacy_comparison_included' => $includeLegacy,
            'population' => 'one latest pregame evaluated revision per game, paired frozen-input replay',
            'limitations' => ['Candidate replay is retrospective, not out-of-sample validation.',
                'Missing prior metrics and availability are not backfilled from current data.',
                'Forecast accuracy is not sportsbook ATS or betting profitability.'],
            'summary' => $summaries, 'excluded' => $excluded, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param Collection<int, Game> $games @return Collection<int, CanonicalPrediction> */
    private function forecasts(Collection $games): Collection
    {
        return CanonicalPrediction::query()->select('predictions.*')
            ->join('sport_events', 'sport_events.id', '=', 'predictions.sport_event_id')
            ->with(['latestEvaluation', 'calculationRun.inputSnapshot'])
            ->whereIn('predictions.sport_event_id', $games->pluck('sport_event_id')->filter())
            ->where('predictions.sport', 'cfb')
            ->where('predictions.id', function ($query): void {
                $query->from('predictions as candidate')->select('candidate.id')
                    ->whereColumn('candidate.sport_event_id', 'predictions.sport_event_id')
                    ->where('candidate.sport', 'cfb')->where('candidate.phase', 'pregame')
                    ->whereIn('candidate.publication_state', ['published', 'superseded'])
                    ->whereColumn('candidate.published_at', '<', 'sport_events.starts_at')
                    ->orderByDesc('candidate.revision')->limit(1);
            })->get()->keyBy('sport_event_id');
    }
}
