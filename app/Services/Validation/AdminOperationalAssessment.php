<?php

namespace App\Services\Validation;

use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Support\SportCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AdminOperationalAssessment
{
    /** Numeric facts retain their source definitions; overlapping counts are never summed. */
    private const COUNT_FACTS = [
        'problem_games', 'blocking_odds_problem_games', 'games_missing_predictions',
        'games_with_stale_predictions', 'completed_games_missing_full_team_stats',
        'games_missing_odds', 'games_with_stale_odds', 'games_with_missing_required_markets',
        'games_missing_player_stats', 'games_missing_team_stats', 'games_missing_plays',
        'games_missing_grading', 'games_missing_game_scores', 'stale_games',
        'missing_predictions', 'missing_live_model_fields', 'stale_live_models',
        'teams_missing_stats',
        'expected_forecast_holds', 'games_with_frozen_prior_season_metrics',
        'games_with_frozen_current_season_metrics', 'games_with_unusable_team_metrics',
    ];

    public function __construct(private readonly ValidationRunRecorder $recorder) {}

    /** @return array<string, mixed> */
    public function build(?CarbonInterface $observedAt = null): array
    {
        $observedAt = CarbonImmutable::instance($observedAt ?? now())->timezone('America/Chicago');
        $sports = [];
        $actions = [];
        $gaps = [];
        $inactivity = [];
        $overall = 'healthy';

        foreach (SportCatalog::ALL as $sport) {
            $inSeason = in_array($observedAt->month, (array) config('healthchecks.season_months.'.$sport, []), true);
            if (! $inSeason) {
                $inactivity[] = strtoupper($sport).': seasonal jobs may be inactive under configured month guards; existing findings remain visible.';
            }
            foreach (['full', 'data'] as $scope) {
                $entry = $this->assessScope($sport, $scope, $observedAt, $inSeason);
                $sports[] = $entry;
                foreach ($entry['evidence_gaps'] as $gap) {
                    $gaps[] = strtoupper($sport).' / '.$scope.': '.$gap;
                }
                if ($entry['status'] === 'failing') {
                    $overall = 'attention';
                } elseif ($entry['status'] === 'warning' && $overall !== 'attention') {
                    $overall = 'watch';
                } elseif ($scope === 'full' && $inSeason && ($entry['status'] === 'unknown' || $entry['evidence_incomplete']) && $overall === 'healthy') {
                    $overall = 'unknown';
                }
                foreach ($entry['_actions'] as $action) {
                    $actions[] = $action;
                }
                unset($sports[array_key_last($sports)]['_actions']);
            }
        }
        $actions = collect($actions)->sortByDesc('_priority')->unique(fn (array $action): string => $action['sport'].'|'.$action['action'])
            ->take(3)->map(function (array $action): array {
                unset($action['_priority']);

                return $action;
            })->values()->all();

        return [
            'status' => $overall,
            'headline' => match ($overall) {
                'attention' => 'Validation findings require attention',
                'watch' => 'Validation warnings require review',
                'healthy' => 'Available completed validations are passing',
                default => 'Current validation coverage is incomplete',
            },
            'observed_at' => $observedAt->toIso8601String(),
            'sports' => $sports,
            'actions' => $actions,
            'evidence_gaps' => array_values(array_unique($gaps)),
            'inactivity' => $inactivity,
            'evidence_note' => 'Changes compare stored check identities against a completed run at or before yesterday, or the labeled previous completed run. They do not establish incident onset or current production enforcement. Counts retain source definitions and may overlap; affected users and total distinct affected games are unknown.',
        ];
    }

    /** @return array<string, mixed> */
    private function assessScope(string $sport, string $scope, CarbonImmutable $at, bool $inSeason): array
    {
        $query = $this->recorder->completedChecksQuery('sport:'.$sport, $scope)
            ->where('started_at', '<=', $at)->where('completed_at', '<=', $at);
        $current = (clone $query)->latest('completed_at')->latest('id')->first();
        $attempt = $this->attemptQuery($sport, $scope)->where('started_at', '<=', $at)->latest('started_at')->latest('id')->first();
        $baseline = $current === null ? null : (clone $query)->where('id', '<', $current->id)
            ->where('completed_at', '<=', $at->subDay())->latest('completed_at')->latest('id')->first();
        $baselineKind = $baseline === null ? 'unavailable' : 'yesterday';
        if ($current !== null && $baseline === null) {
            $baseline = (clone $query)->where('id', '<', $current->id)->latest('completed_at')->latest('id')->first();
            if ($baseline !== null) {
                $baselineKind = 'previous_completed';
            }
        }
        $gaps = [];
        $incomplete = $current === null;
        $ageMinutes = $current === null ? null : (int) $current->completed_at->diffInMinutes($at);
        if ($current !== null && $inSeason && $ageMinutes > (int) config('alerts.admin_report.validation_max_age_minutes', 1440)) {
            $gaps[] = 'The latest completed validation exceeds the configured report age limit; current check coverage is stale, independently of source data freshness.';
            $incomplete = true;
        }
        if ($current === null) {
            $gaps[] = 'No completed check run is available.';
        } elseif ($baseline === null) {
            $gaps[] = 'No comparable completed baseline; change classifications are unavailable.';
        }
        if ($attempt !== null && ($current === null || $attempt->id > $current->id) && ! $this->recorder->checksCompleted($attempt)) {
            $incomplete = true;
            $gaps[] = 'The latest attempted validation did not complete its checks; the displayed completed snapshot may predate that attempt.';
        }
        if ($current !== null && data_get($current->summary, 'post_processing_errors', []) !== []) {
            $gaps[] = 'Checks completed, but optional summary or notification processing reported an error.';
        }
        $changes = ['new' => [], 'worsened' => [], 'persistent' => [], 'recovered' => [], 'unobserved' => []];
        $actions = [];
        $currentFindings = $current === null ? collect() : $this->indexedFindings($current);
        $previousFindings = $baseline === null ? collect() : $this->indexedFindings($baseline);
        foreach ($currentFindings as $identity => $finding) {
            $previous = $previousFindings->get($identity);
            $classification = $baseline === null ? null : $this->classification($finding, $previous);
            $payload = $this->findingPayload($finding, $previous, $sport, $current);
            if ($classification !== null) {
                $changes[$classification][] = $payload;
            }
            if (in_array($finding->status, ['failing', 'warning'], true) && trim((string) $finding->recommended_action) !== '') {
                $actions[] = [
                    'sport' => $sport, 'scope' => $scope, 'message' => $finding->message,
                    'action' => $finding->recommended_action, 'url' => $payload['url'], 'run_id' => $current->id,
                    'remediation_state' => 'suggested',
                    '_priority' => ($finding->status === 'failing' ? 100 : 50) + match ($classification) {
                        'worsened' => 20, 'new' => 10, default => 0,
                    },
                ];
            }
        }
        foreach ($previousFindings as $identity => $finding) {
            if (! $currentFindings->has($identity) && in_array($finding->status, ['failing', 'warning'], true)) {
                $changes['unobserved'][] = $this->findingPayload($finding, $finding, $sport, $baseline);
                $gaps[] = 'A previously unhealthy check is absent from the current run; recovery is unverified.';
            }
        }
        if ($current !== null && $currentFindings->isEmpty()) {
            $incomplete = true;
            $gaps[] = 'The completed run contains no finding records; its check coverage cannot be verified.';
        }
        $expectedHoldCount = $currentFindings->map(fn (ValidationFinding $finding): mixed => data_get($finding->facts, 'expected_forecast_holds'))
            ->filter(fn (mixed $value): bool => is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && floor((float) $value) === (float) $value)
            ->max() ?? 0;

        return [
            'sport' => $sport, 'scope' => $scope, 'status' => $current?->status ?? 'unknown',
            'in_season' => $inSeason, 'run_id' => $current?->id,
            'completed_at' => $current?->completed_at?->copy()->timezone('America/Chicago')->toIso8601String(),
            'age_minutes' => $ageMinutes, 'evidence_incomplete' => $incomplete,
            'expected_hold_count' => (int) $expectedHoldCount,
            'url' => $this->url($sport, $current?->id),
            'baseline_run_id' => $baseline?->id, 'baseline_kind' => $baselineKind,
            'baseline_completed_at' => $baseline?->completed_at?->copy()->timezone('America/Chicago')->toIso8601String(),
            'findings' => $currentFindings->whereIn('status', ['failing', 'warning'])->map(fn (ValidationFinding $finding): array => $this->findingPayload($finding, null, $sport, $current))->values()->all(),
            'changes' => $changes, 'evidence_gaps' => array_values(array_unique($gaps)),
            'impact_note' => 'Measured counts appear per check and may overlap. Distinct affected games and affected users are unknown. Suggested actions have not been confirmed executed.',
            'remediation_state' => $actions === [] ? 'none' : 'suggested',
            'latest_attempt' => $attempt === null ? null : [
                'id' => $attempt->id, 'status' => $attempt->status,
                'checks_execution_status' => data_get($attempt->summary, 'checks_execution_status', data_get($attempt->summary, 'execution_status', 'unknown')),
                'started_at' => $attempt->started_at?->toIso8601String(), 'completed_at' => $attempt->completed_at?->toIso8601String(),
            ],
            '_actions' => $actions,
        ];
    }

    private function attemptQuery(string $sport, string $scope): Builder
    {
        return ValidationRun::query()->where('command_name', 'healthcheck:validate-data')->where('scope', 'sport:'.$sport)
            ->where(function (Builder $query) use ($scope): void {
                $query->where('summary->validation_scope', $scope);
                if ($scope === 'full') {
                    $query->orWhereNull('summary->validation_scope');
                }
            });
    }

    /** @return Collection<string, ValidationFinding> */
    private function indexedFindings(ValidationRun $run): Collection
    {
        return $run->findings()->get()->sortByDesc(fn (ValidationFinding $finding): int => $this->rank($finding->status))
            ->unique(fn (ValidationFinding $finding): string => $finding->check_type.'|'.$finding->scope_type.'|'.$finding->scope_id)
            ->keyBy(fn (ValidationFinding $finding): string => $finding->check_type.'|'.$finding->scope_type.'|'.$finding->scope_id);
    }

    private function classification(ValidationFinding $current, ?ValidationFinding $previous): ?string
    {
        $active = $this->rank($current->status) > 0;
        $previousActive = $previous !== null && $this->rank($previous->status) > 0;

        return match (true) {
            $active && ! $previousActive => 'new',
            $active && $this->rank($current->status) > $this->rank($previous->status) => 'worsened',
            $active && $previous !== null && $current->status === $previous->status && $this->countsIncreased($current, $previous) => 'worsened',
            $active => 'persistent',
            $current->status === 'passing' && $previousActive => 'recovered',
            default => null,
        };
    }

    private function countsIncreased(ValidationFinding $current, ValidationFinding $previous): bool
    {
        foreach (self::COUNT_FACTS as $key) {
            $currentCount = data_get($current->facts, $key);
            $previousCount = data_get($previous->facts, $key);
            if (is_numeric($currentCount) && is_numeric($previousCount)
                && floor((float) $currentCount) === (float) $currentCount
                && floor((float) $previousCount) === (float) $previousCount
                && (float) $previousCount >= 0 && (float) $currentCount > (float) $previousCount) {
                return true;
            }
        }

        return false;
    }

    private function rank(?string $status): int
    {
        return match ($status) {
            'failing' => 2, 'warning' => 1, default => 0
        };
    }

    /** @return array<string, mixed> */
    private function findingPayload(ValidationFinding $finding, ?ValidationFinding $previous, string $sport, ValidationRun $run): array
    {
        $metrics = [];
        foreach (self::COUNT_FACTS as $key) {
            $value = data_get($finding->facts, $key);
            if (is_numeric($value) && (float) $value >= 0 && floor((float) $value) === (float) $value) {
                $metrics[$key] = (int) $value;
            }
        }

        return [
            'check_type' => $finding->check_type, 'message' => $finding->message,
            'status' => $finding->status, 'previous_status' => $previous?->status,
            'metrics' => $metrics, 'url' => $this->url($sport, $run->id),
        ];
    }

    private function url(string $sport, ?int $runId): string
    {
        return route('admin.healthchecks', array_filter(['view' => 'validation', 'sport' => $sport, 'validation_run' => $runId], fn ($value): bool => $value !== null));
    }
}
