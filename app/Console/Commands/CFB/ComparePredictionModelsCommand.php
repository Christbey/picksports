<?php

namespace App\Console\Commands\CFB;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ComparePredictionModelsCommand extends Command
{
    protected $signature = 'cfb:compare-prediction-models {--season=} {--from-week=1} {--to-week=2}';

    protected $description = 'Read-only paired comparison of evaluated pregame forecasts and candidate replay on frozen inputs';

    public function handle(CfbCalculator $calculator, CfbCalculationReleaseDefinition $definition): int
    {
        $values = ['season' => $this->option('season'), 'from' => $this->option('from-week'), 'to' => $this->option('to-week')];
        if (Validator::make($values, ['season' => 'required|integer|min:2000|max:2100',
            'from' => 'required|integer|min:0|max:20', 'to' => 'required|integer|gte:from|max:20'])->fails()) {
            $this->error('Supply a season and an ordered week range.');

            return self::FAILURE;
        }
        $games = Game::with(['sportEvent', 'prediction'])->where('season', $values['season'])
            ->whereBetween('week', [$values['from'], $values['to']])->where('status', 'STATUS_FINAL')->get();
        $config = $definition->configuration();
        $release = new CalculationReleaseData('offline-candidate', 'cfb', 'pregame', $definition->calculatorName(), 'rules',
            $definition->semanticVersion(), 'working-tree', hash('sha256', json_encode($config)), $definition->inputSchemaVersion(), $config);
        $rows = [];
        $excluded = [];
        foreach ($games as $game) {
            if (! $game->sportEvent?->starts_at) {
                $excluded[$game->id] = 'missing_event_start';

                continue;
            }
            $prediction = CanonicalPrediction::with(['latestEvaluation', 'calculationRun.inputSnapshot'])
                ->where('sport_event_id', $game->sport_event_id)->where('phase', 'pregame')
                ->whereIn('publication_state', ['published', 'superseded'])
                ->where('published_at', '<', $game->sportEvent->starts_at)->orderByDesc('revision')->first();
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
                'legacy_comparable' => $game->prediction?->updated_at?->lt($game->sportEvent->starts_at) ?? false,
                'candidate_input_quality' => $output->metadata['input_quality'] ?? null];
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
            'population' => 'one latest pregame evaluated revision per game, paired frozen-input replay',
            'limitations' => ['Candidate replay is retrospective, not out-of-sample validation.',
                'Missing prior metrics and availability are not backfilled from current data.',
                'Forecast accuracy is not sportsbook ATS or betting profitability.'],
            'summary' => $summaries, 'excluded' => $excluded, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
