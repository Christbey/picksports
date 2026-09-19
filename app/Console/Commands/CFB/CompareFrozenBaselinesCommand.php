<?php

namespace App\Console\Commands\CFB;

use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbFrozenBaselineComparison;
use Illuminate\Console\Command;

class CompareFrozenBaselinesCommand extends Command
{
    protected $signature = 'cfb:compare-frozen-baselines {--season= : Required evaluation season}';

    protected $description = 'Read-only comparison of fixed FPI/Elo baselines using frozen contemporaneous pregame snapshots';

    public function handle(CfbFrozenBaselineComparison $comparison): int
    {
        if (! ctype_digit((string) $this->option('season'))) {
            $this->error('An explicit --season is required.');

            return self::FAILURE;
        }
        $rows = $excluded = $cohorts = [];
        $events = SportEvent::query()->where('sport', 'cfb')->where('season', (int) $this->option('season'))
            ->whereHas('cfbGame', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->with('cfbGame')->orderBy('starts_at')->get();
        foreach ($events as $event) {
            $game = $event->cfbGame;
            if (! $event->starts_at || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
                $excluded['missing_result_or_kickoff'] = ($excluded['missing_result_or_kickoff'] ?? 0) + 1;

                continue;
            }
            $snapshot = $event->inputSnapshots()->where('phase', 'pregame')->where('pregame_safety_status', 'verified')
                ->where('captured_at', '<', $event->starts_at)->orderByDesc('captured_at')->orderByDesc('id')->first();
            if (! $snapshot || ($snapshot->latest_source_available_at && $snapshot->latest_source_available_at->gt($snapshot->captured_at))) {
                $excluded['no_safe_frozen_snapshot'] = ($excluded['no_safe_frozen_snapshot'] ?? 0) + 1;

                continue;
            }
            $evaluation = $comparison->evaluate($snapshot->inputs, $snapshot->captured_at, $event->starts_at,
                (float) $game->home_score - (float) $game->away_score);
            foreach ($evaluation['unavailable'] ?? [] as $reason) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            }
            foreach ($evaluation['errors'] ?? [] as $model => $error) {
                $cohorts['available'][$model][] = $error;
                if ($evaluation['paired']) {
                    $cohorts['paired'][$model][] = $error;
                    $cohorts['week_'.($game->week ?? 'unknown')][$model][] = $error;
                    // This is a model-margin bucket, not a market favorite-size claim.
                    $bucket = abs($evaluation['margins']['fpi']) >= 20 ? 'fpi_margin_20_plus' : 'fpi_margin_under_20';
                    $cohorts[$bucket][$model][] = $error;
                }
            }
            $rows[] = ['game_id' => $game->id, 'snapshot_id' => $snapshot->id, 'starts_at' => $event->starts_at,
                'captured_at' => $snapshot->captured_at, ...$evaluation];
        }
        $summary = [];
        foreach ($cohorts as $cohort => $models) {
            foreach ($models as $model => $errors) {
                $summary[$cohort][$model] = $comparison->summarize($errors);
            }
        }
        $this->line(json_encode(['season' => (int) $this->option('season'), 'completed_events' => $events->count(),
            'policy' => 'Latest safe frozen pregame snapshot per event; fixed coefficients; no outcome tuning; no mutable historical reconstruction.',
            'comparison_status' => isset($summary['paired']) ? 'descriptive_fixed_baselines_not_release_validation' : 'insufficient_paired_contemporaneous_evidence',
            'coefficients' => ['elo_points_per_score_point' => 25, 'home_field_points' => 2.2, 'blend_elo_weight' => 0.5],
            'limitations' => ['Not the contextual production calculator; coefficients are not newly calibrated.',
                'Retrospective Elo rebuilt after kickoff cannot enter frozen snapshots.',
                'ATS, CLV and probability calibration are not evaluated; this command does not read market quote history.',
                'Available cohorts may differ; compare models only on the paired cohort.'],
            'excluded' => $excluded, 'summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
