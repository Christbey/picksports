<?php

namespace App\Console\Commands;

use App\Models\SportsAiPredictionAnalysis;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class OperationsAiReviewCommand extends Command
{
    protected $signature = 'operations:ai-review
        {--sport= : Sport to review: mlb, nba, nfl, cbb, cfb, wcbb, wnba}
        {--season= : Optional season override}
        {--date= : As-of date for saved AI analyses, defaults to today}
        {--json : Output machine-readable JSON}';

    protected $description = 'Review latest validation, season stage, and AI publishing state for operational readiness';

    public function handle(SeasonStageService $seasonStageService): int
    {
        $sport = strtolower((string) $this->option('sport'));

        if ($sport === '' || ! in_array($sport, SportCatalog::ALL, true)) {
            $this->error('A supported --sport option is required.');

            return self::FAILURE;
        }

        $run = $this->latestValidationRun($sport);
        if (! $run) {
            $this->error("No validation run found for {$sport}. Run healthcheck:validate-data --sport={$sport} first.");

            return self::FAILURE;
        }

        $season = $this->option('season');
        $date = (string) ($this->option('date') ?: now()->toDateString());
        $stage = $seasonStageService->context(
            $sport,
            $season !== null && $season !== '' ? (int) $season : null,
            $date,
        );
        $findings = $run->findings()
            ->where('sport', $sport)
            ->orderByRaw("CASE status WHEN 'failing' THEN 3 WHEN 'warning' THEN 2 WHEN 'passing' THEN 1 ELSE 0 END DESC")
            ->latest('detected_at')
            ->get();
        $aiSummary = is_array($run->ai_summary) ? $run->ai_summary : [];
        $analyses = $this->aiAnalyses($sport, $date);
        $report = $this->buildReport($sport, $run, $stage->toArray(), $findings, $aiSummary, $analyses);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    private function latestValidationRun(string $sport): ?ValidationRun
    {
        if (! Schema::hasTable('validation_runs')) {
            return null;
        }

        return ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->whereIn('scope', ['sport:'.$sport, 'all_sports'])
            ->latest('completed_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, SportsAiPredictionAnalysis>
     */
    private function aiAnalyses(string $sport, string $date): Collection
    {
        if (! Schema::hasTable('sports_ai_prediction_analyses')) {
            return collect();
        }

        return SportsAiPredictionAnalysis::query()
            ->where('sport', $sport)
            ->whereDate('as_of_date', $date)
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  Collection<int, ValidationFinding>  $findings
     * @param  array<string, mixed>  $aiSummary
     * @param  Collection<int, SportsAiPredictionAnalysis>  $analyses
     * @return array<string, mixed>
     */
    private function buildReport(
        string $sport,
        ValidationRun $run,
        array $stage,
        Collection $findings,
        array $aiSummary,
        Collection $analyses,
    ): array {
        $failing = $findings->where('status', 'failing')->values();
        $warnings = $findings->where('status', 'warning')->values();
        $requiredActions = $findings
            ->whereIn('status', ['failing', 'warning'])
            ->pluck('recommended_action')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $guardrailDecisions = $analyses
            ->map(fn (SportsAiPredictionAnalysis $analysis): string => (string) data_get($analysis->metadata, 'shadow_agents.publishing_guardrail.decision', 'missing'))
            ->countBy()
            ->all();

        $stageGroup = (string) data_get($stage, 'stage_group', 'unknown');
        $lowVolumeExpected = $stageGroup === 'championship'
            && $warnings->contains(fn (ValidationFinding $finding): bool => $finding->check_type === 'validation_game_coverage');

        $status = match (true) {
            $failing->isNotEmpty() => 'blocked',
            $warnings->isNotEmpty() && ! $lowVolumeExpected => 'degraded',
            $warnings->isNotEmpty() => 'watch',
            default => 'clear',
        };

        return [
            'schema_version' => 'operations_ai_review_v1',
            'generated_at' => now()->toIso8601String(),
            'sport' => $sport,
            'status' => $status,
            'safe_to_publish' => $status !== 'blocked',
            'trust_score' => (int) data_get($aiSummary, 'trust_score', $this->fallbackTrustScore($failing->count(), $warnings->count(), $lowVolumeExpected)),
            'latest_validation' => [
                'run_id' => $run->id,
                'status' => $run->status,
                'completed_at' => $run->completed_at?->toIso8601String(),
                'summary' => $run->summary ?? [],
                'headline' => data_get($aiSummary, 'headline'),
                'latest_data_fresh_at' => data_get($aiSummary, 'latest_data_fresh_at'),
            ],
            'season_stage' => $stage,
            'findings' => [
                'failing' => $failing->map(fn (ValidationFinding $finding): array => $this->findingPayload($finding))->all(),
                'warnings' => $warnings->map(fn (ValidationFinding $finding): array => $this->findingPayload($finding))->all(),
                'low_volume_expected_for_stage' => $lowVolumeExpected,
            ],
            'publishing' => [
                'analyses_today' => $analyses->count(),
                'guardrail_decisions' => $guardrailDecisions,
                'blocked_outputs' => array_values((array) data_get($aiSummary, 'blocked_outputs', [])),
                'safe_adjustments' => array_values((array) data_get($aiSummary, 'safe_adjustments', [])),
            ],
            'recommended_actions' => $requiredActions === []
                ? ['No operational action required. Continue scheduled sentinel monitoring.']
                : $requiredActions,
            'operator_notes' => $this->operatorNotes($status, $stage, $lowVolumeExpected, $analyses),
            'generated_by' => data_get($aiSummary, 'generated_by', 'operations-ai-review-v1'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findingPayload(ValidationFinding $finding): array
    {
        return [
            'check_type' => $finding->check_type,
            'status' => $finding->status,
            'message' => $finding->message,
            'recommended_action' => $finding->recommended_action,
            'facts' => $this->compactFacts(is_array($finding->facts) ? $finding->facts : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function compactFacts(array $facts): array
    {
        $keys = [
            'sample_game_ids',
            'sample_missing_game_ids',
            'sample_expected_missing_game_ids',
            'sample_stale_game_ids',
            'sample_missing_odds_game_ids',
            'sample_expected_missing_odds_game_ids',
            'sample_missing_required_market_game_ids',
            'sample_stale_odds_game_ids',
            'sample_missing_weather_game_ids',
            'sample_stale_weather_game_ids',
            'sample_unknown_roof_game_ids',
            'sample_market_ready_weather_problem_game_ids',
            'sample_market_ready_missing_weather_game_ids',
            'sample_unscored_game_ids',
            'sample_games',
            'sample_roof_context_games',
            'missing_core_fields',
            'date_leakage',
            'duplicate_espn_event_ids',
            'games_missing_odds',
            'games_with_missing_required_markets',
            'games_with_stale_odds',
            'provider_unavailable_far_odds',
            'provider_unavailable_soft_window_odds',
            'provider_unavailable_expected_window_odds',
            'games_missing_player_props',
            'provider_unavailable_far_games',
            'provider_unavailable_soft_window_games',
            'provider_unavailable_expected_window_games',
            'games_with_stale_player_props',
            'games_with_unscored_player_props',
            'games_missing_weather',
            'games_with_stale_weather',
            'games_with_unknown_roof_status',
            'market_ready_weather_problem_games',
            'market_ready_missing_weather_games',
            'blocking_odds_problem_games',
            'rules_checked',
            'violations',
            'missing_heartbeats',
            'oldest_game_date',
            'newest_game_date',
        ];

        return collect($facts)
            ->only($keys)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '')
            ->all();
    }

    private function fallbackTrustScore(int $failing, int $warnings, bool $lowVolumeExpected): int
    {
        $effectiveWarnings = $lowVolumeExpected ? max(0, $warnings - 1) : $warnings;

        return max(0, min(100, 100 - ($failing * 20) - ($effectiveWarnings * 5)));
    }

    /**
     * @param  array<string, mixed>  $stage
     * @param  Collection<int, SportsAiPredictionAnalysis>  $analyses
     * @return array<int, string>
     */
    private function operatorNotes(string $status, array $stage, bool $lowVolumeExpected, Collection $analyses): array
    {
        $notes = [
            'Stage context: '.strtoupper((string) data_get($stage, 'sport')).' is in '.(string) data_get($stage, 'stage').' / '.(string) data_get($stage, 'stage_group').'.',
        ];

        if ($lowVolumeExpected) {
            $notes[] = 'Low upcoming game volume is expected for the championship stage and should not block publishing.';
        }

        if ($analyses->isEmpty()) {
            $notes[] = 'No saved AI daily prediction analyses found for the selected as-of date.';
        }

        if ($status === 'clear') {
            $notes[] = 'Validation and AI guardrail context do not require blocking user-facing outputs.';
        } elseif ($status === 'blocked') {
            $notes[] = 'One or more failing validation findings should block official publishing until repaired.';
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info(strtoupper((string) $report['sport']).' Operations AI Review');
        $this->line('Status: '.$report['status'].' | Trust: '.$report['trust_score'].' | Safe to publish: '.($report['safe_to_publish'] ? 'yes' : 'no'));
        $this->line('Stage: '.data_get($report, 'season_stage.stage').' / '.data_get($report, 'season_stage.stage_group'));
        $this->line('Validation run: '.data_get($report, 'latest_validation.run_id').' ('.data_get($report, 'latest_validation.status').')');
        $this->line('AI analyses today: '.data_get($report, 'publishing.analyses_today'));

        $blockingFindings = array_merge(
            (array) data_get($report, 'findings.failing', []),
            (array) data_get($report, 'findings.warnings', []),
        );

        if ($blockingFindings !== []) {
            $this->newLine();
            $this->line('Finding details:');
            foreach ($blockingFindings as $finding) {
                $this->line(' - '.data_get($finding, 'check_type').': '.data_get($finding, 'message'));

                $facts = data_get($finding, 'facts', []);
                if (is_array($facts) && $facts !== []) {
                    $this->line('   facts: '.json_encode($facts, JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $this->newLine();
        $this->line('Recommended actions:');
        foreach ($report['recommended_actions'] as $action) {
            $this->line(' - '.$action);
        }

        $this->newLine();
        $this->line('Operator notes:');
        foreach ($report['operator_notes'] as $note) {
            $this->line(' - '.$note);
        }
    }
}
