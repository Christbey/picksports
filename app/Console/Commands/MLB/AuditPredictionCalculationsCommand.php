<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPredictionCalculationAuditService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AuditPredictionCalculationsCommand extends Command
{
    protected $signature = 'mlb:audit-prediction-calculations
        {--season= : Filter by season}
        {--from= : Start game date in YYYY-MM-DD}
        {--to= : End game date in YYYY-MM-DD}
        {--strict-pregame : Warn when point-in-time metadata is incomplete}
        {--sample=50 : Number of sample failures/warnings to show}
        {--json : Output structured JSON}';

    protected $description = 'Audit MLB prediction calculation invariants and point-in-time safety metadata.';

    public function handle(MlbPredictionCalculationAuditService $audit): int
    {
        $sampleLimit = max(1, (int) $this->option('sample'));
        $predictions = $this->predictionQuery()->get();

        $summary = [
            'predictions_scanned' => $predictions->count(),
            'invalid_probabilities' => 0,
            'probabilities_not_sum_one' => 0,
            'winner_probability_disagreements' => 0,
            'invalid_spread_score_relationship' => 0,
            'invalid_total_score_relationship' => 0,
            'missing_model_version' => 0,
            'missing_feature_version' => 0,
            'missing_blend_version' => 0,
            'missing_point_in_time_metadata' => 0,
            'missing_source_timestamps' => 0,
            'live_fields_present' => 0,
            'games_excluded_due_missing_final_scores' => 0,
            'games_excluded_due_postponed_or_suspended_status' => 0,
        ];
        $hardFailures = [];
        $warnings = [];

        foreach ($predictions as $prediction) {
            $prediction->loadMissing(['game.homeTeam', 'game.awayTeam', 'game.weather']);
            $game = $prediction->game;

            if ($game && in_array($game->status, [config('mlb.statuses.postponed'), config('mlb.statuses.suspended'), config('mlb.statuses.canceled')], true)) {
                $summary['games_excluded_due_postponed_or_suspended_status']++;

                continue;
            }

            if ($game && $game->status === config('mlb.statuses.final') && ($game->home_score === null || $game->away_score === null)) {
                $summary['games_excluded_due_missing_final_scores']++;

                continue;
            }

            $failures = $audit->hardInvariantFailures($prediction);
            $rowWarnings = $audit->warnings($prediction, $audit->latestSnapshot($prediction));
            $homeProbability = is_numeric($prediction->win_probability) ? (float) $prediction->win_probability : null;
            $spread = is_numeric($prediction->predicted_spread) ? (float) $prediction->predicted_spread : null;
            $total = is_numeric($prediction->predicted_total) ? (float) $prediction->predicted_total : null;

            if (in_array('home_win_probability_out_of_range', $failures, true) || in_array('win_probability_is_missing_or_not_finite', $failures, true)) {
                $summary['invalid_probabilities']++;
            }

            if (in_array('probabilities_do_not_sum_to_one', $failures, true)) {
                $summary['probabilities_not_sum_one']++;
            }

            if (in_array('home_favored_spread_disagrees_with_probability', $failures, true)
                || in_array('away_favored_spread_disagrees_with_probability', $failures, true)) {
                $summary['winner_probability_disagreements']++;
            }

            if (in_array('derived_team_score_would_be_negative', $failures, true)) {
                $summary['invalid_spread_score_relationship']++;
            }

            if ($total !== null && $total <= 0) {
                $summary['invalid_total_score_relationship']++;
                $failures[] = 'predicted_total_not_positive';
            }

            foreach (['model_version', 'feature_version', 'blend_version'] as $field) {
                if (in_array("{$field}_missing", $failures, true)) {
                    $summary["missing_{$field}"]++;
                }
            }

            if (in_array('missing_feature_snapshot', $rowWarnings, true)
                || in_array('team_metric_point_in_time_limitation', $rowWarnings, true)) {
                $summary['missing_point_in_time_metadata']++;
            }

            if (in_array('market_context_not_proven_pregame_safe', $rowWarnings, true)
                || in_array('weather_applied_without_observed_at_timestamp', $rowWarnings, true)) {
                $summary['missing_source_timestamps']++;
            }

            if (in_array('live_fields_present_but_not_core_pregame_inputs', $rowWarnings, true)) {
                $summary['live_fields_present']++;
            }

            if ($failures !== [] && count($hardFailures) < $sampleLimit) {
                $hardFailures[] = $this->sampleRow($prediction, array_values(array_unique($failures)), $homeProbability, $spread, $total);
            }

            if ($rowWarnings !== [] && count($warnings) < $sampleLimit) {
                $warnings[] = $this->sampleRow($prediction, $rowWarnings, $homeProbability, $spread, $total);
            }
        }

        $report = [
            'report_type' => 'mlb_prediction_calculation_audit',
            'season' => $this->option('season') ? (int) $this->option('season') : null,
            'strict_pregame' => (bool) $this->option('strict-pregame'),
            'summary' => $summary,
            'hard_failure_samples' => $hardFailures,
            'warning_samples' => $warnings,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hardFailures === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('MLB Prediction Calculation Audit');
        $this->line('Rows scanned: '.$summary['predictions_scanned']);
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($summary)->map(fn (int $count, string $key): array => [$key, $count])->values()->all()
        );

        if ($hardFailures !== []) {
            $this->newLine();
            $this->error('Hard invariant failures');
            $this->table(['Prediction', 'Game', 'Reasons', 'WP', 'Spread', 'Total'], $this->tableSamples($hardFailures));
        }

        if ($warnings !== []) {
            $this->newLine();
            $this->warn('Warnings');
            $this->table(['Prediction', 'Game', 'Reasons', 'WP', 'Spread', 'Total'], $this->tableSamples($warnings));
        }

        return $hardFailures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function predictionQuery(): Builder
    {
        return Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam', 'game.weather'])
            ->whereHas('game', function (Builder $query): void {
                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }

                if ($this->option('from')) {
                    $query->whereDate('game_date', '>=', (string) $this->option('from'));
                }

                if ($this->option('to')) {
                    $query->whereDate('game_date', '<=', (string) $this->option('to'));
                }
            })
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleRow(Prediction $prediction, array $reasons, ?float $homeProbability, ?float $spread, ?float $total): array
    {
        $game = $prediction->game;

        return [
            'prediction_id' => (int) $prediction->id,
            'game_id' => (int) $prediction->game_id,
            'matchup' => (string) ($game?->short_name ?: $game?->name ?: ''),
            'reasons' => $reasons,
            'home_win_probability' => $homeProbability,
            'predicted_spread' => $spread,
            'predicted_total' => $total,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<int, mixed>>
     */
    private function tableSamples(array $samples): array
    {
        return array_map(fn (array $sample): array => [
            $sample['prediction_id'],
            $sample['game_id'].' '.$sample['matchup'],
            implode(', ', $sample['reasons']),
            $sample['home_win_probability'],
            $sample['predicted_spread'],
            $sample['predicted_total'],
        ], $samples);
    }
}
