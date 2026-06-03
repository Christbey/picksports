<?php

namespace App\Services\Validation;

use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Collection;

class ValidationReviewSummaryService
{
    public function __construct(
        private readonly SportsAiContentService $sportsAiContentService,
    ) {}

    /**
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,latest_data_fresh_at:string,data_schedule_today:array<int,string>,tweak_recommendations:array<int,string>,operational_status:string,trust_score:int,blocked_outputs:array<int,string>,safe_adjustments:array<int,string>,data_quality_notes:array<int,string>,generated_by:string}
     */
    public function summarizeRun(ValidationRun $run): array
    {
        $findings = $run->findings()->orderByDesc('status')->orderBy('sport')->get();

        $aiSummary = $this->sportsAiContentService->generateValidationReviewSummary($findings);

        if ($aiSummary !== null) {
            return $aiSummary;
        }

        return $this->buildFallbackSummary($findings, $run);
    }

    /**
     * @param  Collection<int, ValidationFinding>  $findings
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,latest_data_fresh_at:string,data_schedule_today:array<int,string>,tweak_recommendations:array<int,string>,operational_status:string,trust_score:int,blocked_outputs:array<int,string>,safe_adjustments:array<int,string>,data_quality_notes:array<int,string>,generated_by:string}
     */
    public function buildFallbackSummary(Collection $findings, ValidationRun $run): array
    {
        $failing = $findings->where('status', 'failing');
        $warning = $findings->where('status', 'warning');
        $sports = $findings->pluck('sport')->filter()->unique()->values()->all();
        $sportLabel = $sports === [] ? 'validation run' : strtoupper(implode(', ', $sports));

        $grouped = $findings
            ->groupBy('check_type')
            ->map(fn (Collection $items, string $checkType) => [
                'check_type' => $checkType,
                'count' => $items->count(),
                'sample_message' => (string) $items->first()?->message,
            ])
            ->sortByDesc('count')
            ->values();

        $highlights = $grouped
            ->take(4)
            ->map(function (array $group): string {
                return sprintf(
                    '%s: %d finding%s. %s',
                    str_replace('_', ' ', ucfirst((string) preg_replace('/^validation_/', '', $group['check_type']))),
                    $group['count'],
                    $group['count'] === 1 ? '' : 's',
                    $group['sample_message']
                );
            })
            ->all();

        $recommendedActions = $findings
            ->pluck('recommended_action')
            ->filter(fn ($action) => is_string($action) && trim($action) !== '')
            ->unique()
            ->values()
            ->take(4)
            ->all();

        if ($recommendedActions === []) {
            $recommendedActions = ['Re-run healthcheck:validate-data after correcting the underlying data pipeline issues.'];
        }

        $completedAt = $run->completed_at?->toDayDateTimeString() ?? 'N/A';
        $latestDataFreshAt = $run->status === 'passing'
            ? 'Fresh as of '.$completedAt
            : 'Not fully fresh in the latest run completed '.$completedAt;

        $tweakRecommendations = $this->buildTweakRecommendations($findings);
        $blockedOutputs = $this->blockedOutputs($findings);
        $safeAdjustments = array_values(array_slice(array_filter(
            array_map(fn ($action) => trim((string) $action), $recommendedActions),
            fn ($action) => $action !== ''
        ), 0, 6));
        $operationalStatus = match (true) {
            $failing->count() >= 5 => 'critical',
            $failing->isNotEmpty() => 'degraded',
            $warning->isNotEmpty() => 'watch',
            default => 'healthy',
        };
        $trustScore = max(0, min(100, 100 - ($failing->count() * 15) - ($warning->count() * 5)));

        return [
            'headline' => sprintf('%s validation summary', $sportLabel),
            'intro' => sprintf(
                'This run recorded %d failing and %d warning findings across %d total checks.',
                $failing->count(),
                $warning->count(),
                $findings->count()
            ),
            'highlights' => $highlights,
            'recommended_actions' => array_map(fn ($action) => (string) $action, $recommendedActions),
            'latest_data_fresh_at' => $latestDataFreshAt,
            'data_schedule_today' => [
                'Live scoreboards refresh every 5 minutes during active windows.',
                'Game details, player stats, and plays refresh every 30 minutes.',
                'Odds and futures refresh every 4 hours for active sports.',
                'Daily validation runs from 06:40 to 07:25 by sport.',
                'Admin report sends after validation at 07:30.',
            ],
            'tweak_recommendations' => $tweakRecommendations,
            'operational_status' => $operationalStatus,
            'trust_score' => $trustScore,
            'blocked_outputs' => $blockedOutputs,
            'safe_adjustments' => $safeAdjustments,
            'data_quality_notes' => $this->dataQualityNotes($findings),
            'generated_by' => 'template-validation-summary-v1',
        ];
    }

    /**
     * @param  Collection<int, ValidationFinding>  $findings
     * @return array<int, string>
     */
    private function buildTweakRecommendations(Collection $findings): array
    {
        $recommendations = [];

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_current_day_game_data_freshness')) {
            $recommendations[] = 'Keep details syncs running after finals so team, player, and play stats close out before reports.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_odds_completeness')) {
            $recommendations[] = 'Run odds sync closer to digest generation when odds coverage warnings appear.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_weather_completeness')) {
            $recommendations[] = 'Refresh weather before generating MLB or NFL totals when weather coverage warnings appear.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_injury_freshness')) {
            $recommendations[] = 'Refresh injuries before predictions when injury freshness warnings appear.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_player_prop_freshness')) {
            $recommendations[] = 'Refresh player props closer to digest generation when prop freshness warnings appear.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_futures_odds_freshness')) {
            $recommendations[] = 'Refresh futures odds before forecast pages or admin reports when futures warnings appear.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_pipeline_order')) {
            $recommendations[] = 'Rerun downstream predictions or analysis after late upstream syncs.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_prediction_completeness')) {
            $recommendations[] = 'Regenerate predictions after late data repairs so picks reflect the newest inputs.';
        }

        if ($recommendations === []) {
            $recommendations[] = 'No schedule tweaks needed from this validation run.';
        }

        return array_slice($recommendations, 0, 4);
    }

    /**
     * @param  Collection<int, ValidationFinding>  $findings
     * @return array<int, string>
     */
    private function blockedOutputs(Collection $findings): array
    {
        $blocked = [];

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_odds_completeness')) {
            $blocked[] = 'Official bet classifications that require fresh odds.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_player_prop_freshness')) {
            $blocked[] = 'Player prop recommendations and digest prop sections.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_weather_completeness')) {
            $blocked[] = 'MLB/NFL totals that depend on weather context.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => in_array($finding->check_type, ['validation_current_day_game_data_freshness', 'validation_finalized_data_completeness'], true))) {
            $blocked[] = 'Final-score grading and stat-dependent reports for affected games.';
        }

        if ($findings->contains(fn (ValidationFinding $finding) => $finding->check_type === 'validation_pipeline_order')) {
            $blocked[] = 'Predictions or AI analysis generated before newer upstream syncs.';
        }

        return $blocked === [] ? ['No user-facing outputs need to be blocked from this run.'] : array_slice($blocked, 0, 6);
    }

    /**
     * @param  Collection<int, ValidationFinding>  $findings
     * @return array<int, string>
     */
    private function dataQualityNotes(Collection $findings): array
    {
        return $findings
            ->whereIn('status', ['failing', 'warning'])
            ->take(6)
            ->map(fn (ValidationFinding $finding): string => sprintf(
                '%s %s: %s',
                strtoupper($finding->sport),
                str_replace('validation_', '', $finding->check_type),
                $finding->message
            ))
            ->values()
            ->all();
    }
}
