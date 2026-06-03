<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\HealthcheckSummaryResource;
use App\Models\Healthcheck;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\ValidationRun;
use App\Services\CommandHeartbeatService;
use App\Services\Sports\SportsPipelineRegistry;
use App\Support\SportCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HealthcheckController extends Controller
{
    public function index(Request $request): Response
    {
        $sport = $request->input('sport');
        $view = $request->input('view', 'heartbeat');
        $validationRunId = $request->integer('validation_run');
        $prefix = $view === 'validation' ? 'validation_' : 'heartbeat_';

        // Get the latest check for each sport/check_type combination
        $latestChecks = Healthcheck::query()
            ->select('healthchecks.*')
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('healthchecks')
                    ->groupBy('sport', 'check_type');
            })
            ->where('check_type', 'like', "{$prefix}%")
            ->when($sport, fn ($q) => $q->where('sport', $sport))
            ->orderBy('sport')
            ->orderBy('check_type')
            ->get();
        $latestChecks = collect($this->resourcePayload(HealthcheckSummaryResource::collection($latestChecks)));

        // Group checks by sport
        $checksBySport = $latestChecks->groupBy('sport');

        // Get overall status counts
        $statusCounts = $latestChecks->groupBy('status')->map->count();

        // Get sport filter options
        $sports = SportCatalog::ALL;

        $latestValidationRun = $view === 'validation'
            ? $this->latestValidationRun($sport)
            : null;
        $recentValidationRuns = $view === 'validation'
            ? $this->recentValidationRuns($sport)
            : collect();
        $selectedValidationRun = $view === 'validation'
            ? $this->selectedValidationRun($sport, $validationRunId, $latestValidationRun)
            : null;
        $validationTrend = $view === 'validation'
            ? $this->validationTrend($selectedValidationRun)
            : null;

        return Inertia::render('Admin/Healthchecks', [
            'checks_by_sport' => $checksBySport,
            'status_counts' => $statusCounts,
            'sports' => $sports,
            'latest_validation_run' => $latestValidationRun ? [
                'id' => $latestValidationRun->id,
                'scope' => $latestValidationRun->scope,
                'status' => $latestValidationRun->status,
                'summary' => $latestValidationRun->summary,
                'ai_summary' => $latestValidationRun->ai_summary,
                'ai_generated_at' => $latestValidationRun->ai_generated_at?->toDateTimeString(),
                'completed_at' => $latestValidationRun->completed_at?->toDateTimeString(),
            ] : null,
            'recent_validation_runs' => $recentValidationRuns->map(fn (ValidationRun $run) => [
                'id' => $run->id,
                'scope' => $run->scope,
                'status' => $run->status,
                'summary' => $run->summary,
                'completed_at' => $run->completed_at?->toDateTimeString(),
            ])->values(),
            'selected_validation_run' => $selectedValidationRun ? [
                'id' => $selectedValidationRun->id,
                'scope' => $selectedValidationRun->scope,
                'status' => $selectedValidationRun->status,
                'summary' => $selectedValidationRun->summary,
                'ai_summary' => $selectedValidationRun->ai_summary,
                'ai_generated_at' => $selectedValidationRun->ai_generated_at?->toDateTimeString(),
                'completed_at' => $selectedValidationRun->completed_at?->toDateTimeString(),
                'findings' => $selectedValidationRun->findings
                    ->sortByDesc(fn ($finding) => match ($finding->status) {
                        'failing' => 3,
                        'warning' => 2,
                        default => 1,
                    })
                    ->values()
                    ->map(fn ($finding) => [
                        'id' => $finding->id,
                        'sport' => $finding->sport,
                        'check_type' => $finding->check_type,
                        'status' => $finding->status,
                        'severity' => $finding->severity,
                        'message' => $finding->message,
                        'facts' => $finding->facts,
                        'recommended_action' => $finding->recommended_action,
                        'detected_at' => $finding->detected_at?->toDateTimeString(),
                    ]),
            ] : null,
            'validation_trend' => $validationTrend,
            'ai_publishing' => $this->aiPublishingReview($sport),
            'ai_publishing_trend' => $this->aiPublishingTrend($sport),
            'filters' => [
                'sport' => $sport,
                'view' => $view,
                'validation_run' => $validationRunId,
            ],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $sport = $request->input('sport');
        $mode = $request->input('mode', 'heartbeat');
        $command = $mode === 'validation' ? 'healthcheck:validate-data' : 'healthcheck:run';

        try {
            $exitCode = Artisan::call($command, [
                '--sport' => $sport,
            ]);

            if ($exitCode === 0) {
                return $this->backSuccess(ucfirst($mode).' checks completed successfully.');
            }

            return $this->backWarning(ucfirst($mode).' checks completed with warnings or failures. Check the results below.');
        } catch (\Exception $e) {
            return $this->backError("Failed to run {$mode} checks: ".$e->getMessage());
        }
    }

    public function __construct(
        private readonly CommandHeartbeatService $commandHeartbeatService,
        private readonly SportsPipelineRegistry $sportsPipelineRegistry,
    ) {}

    public function sync(Request $request): RedirectResponse
    {
        $sport = $request->input('sport');
        $checkType = $request->input('check_type');

        if (! $sport || ! $checkType) {
            return $this->backError('Sport and check type are required.');
        }

        try {
            $resolved = $this->getCommandForCheck($sport, $checkType);

            if (! $resolved) {
                return $this->backError("No sync command available for {$sport} {$checkType}.");
            }

            Artisan::call($resolved['command'], $resolved['arguments']);
            $this->commandHeartbeatService->recordSuccess(
                $this->renderCommand($resolved['command'], $resolved['arguments']),
                $sport,
                'manual'
            );

            return $this->backSuccess('Successfully ran '.$this->renderCommand($resolved['command'], $resolved['arguments']).'. Re-run health checks to see updated status.');
        } catch (\Exception $e) {
            if ($sport) {
                $this->commandHeartbeatService->recordFailure('manual:'.$checkType, $sport, 'manual', $e->getMessage());
            }

            return $this->backError('Failed to sync data: '.$e->getMessage());
        }
    }

    /**
     * @return array{command: string, arguments: array<string, mixed>}|null
     */
    protected function getCommandForCheck(string $sport, string $checkType): ?array
    {
        $registryCommand = $this->sportsPipelineRegistry->healthcheckCommand($sport, $checkType);
        if ($registryCommand) {
            return $registryCommand;
        }

        $latestCheck = Healthcheck::query()
            ->where('sport', $sport)
            ->where('check_type', $checkType)
            ->latest('id')
            ->first();

        $recommendedAction = trim((string) data_get($latestCheck?->metadata, 'recommended_action', ''));

        if ($recommendedAction === '') {
            return null;
        }

        return [
            'command' => $recommendedAction,
            'arguments' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function renderCommand(string $command, array $arguments = []): string
    {
        $parts = [$command];

        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = $key.'='.$item;
                }

                continue;
            }

            if (str_starts_with($key, '--')) {
                $parts[] = $key.'='.$value;

                continue;
            }

            $parts[] = (string) $value;
        }

        return implode(' ', $parts);
    }

    protected function latestValidationRun(?string $sport): ?ValidationRun
    {
        return ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->when(
                $sport,
                fn ($query) => $query->where('scope', 'sport:'.$sport),
                fn ($query) => $query->where('scope', 'all_sports')
            )
            ->latest('id')
            ->first();
    }

    protected function recentValidationRuns(?string $sport)
    {
        return ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->when(
                $sport,
                fn ($query) => $query->where('scope', 'sport:'.$sport),
                fn ($query) => $query->whereIn('scope', ['all_sports', ...collect(SportCatalog::ALL)->map(fn ($item) => 'sport:'.$item)->all()])
            )
            ->latest('id')
            ->limit(8)
            ->get();
    }

    protected function selectedValidationRun(?string $sport, ?int $validationRunId, ?ValidationRun $latestValidationRun): ?ValidationRun
    {
        $query = ValidationRun::query()
            ->with('findings')
            ->where('command_name', 'healthcheck:validate-data');

        if ($sport) {
            $query->where('scope', 'sport:'.$sport);
        }

        if ($validationRunId) {
            return $query->whereKey($validationRunId)->first();
        }

        if ($latestValidationRun) {
            return ValidationRun::query()
                ->with('findings')
                ->find($latestValidationRun->id);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function validationTrend(?ValidationRun $selectedValidationRun): ?array
    {
        if (! $selectedValidationRun) {
            return null;
        }

        $previousRun = ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->where('scope', $selectedValidationRun->scope)
            ->where('id', '<', $selectedValidationRun->id)
            ->latest('id')
            ->first();

        $currentSummary = is_array($selectedValidationRun->summary) ? $selectedValidationRun->summary : [];
        $previousSummary = is_array($previousRun?->summary) ? $previousRun->summary : [];

        $currentFailing = (int) ($currentSummary['failing'] ?? 0);
        $currentWarning = (int) ($currentSummary['warning'] ?? 0);
        $currentPassing = (int) ($currentSummary['passing'] ?? 0);
        $previousFailing = (int) ($previousSummary['failing'] ?? 0);
        $previousWarning = (int) ($previousSummary['warning'] ?? 0);
        $previousPassing = (int) ($previousSummary['passing'] ?? 0);

        return [
            'current' => [
                'failing' => $currentFailing,
                'warning' => $currentWarning,
                'passing' => $currentPassing,
            ],
            'previous' => $previousRun ? [
                'id' => $previousRun->id,
                'failing' => $previousFailing,
                'warning' => $previousWarning,
                'passing' => $previousPassing,
                'completed_at' => $previousRun->completed_at?->toDateTimeString(),
            ] : null,
            'delta' => [
                'failing' => $currentFailing - $previousFailing,
                'warning' => $currentWarning - $previousWarning,
                'passing' => $currentPassing - $previousPassing,
            ],
            'direction' => match (true) {
                $currentFailing < $previousFailing => 'improving',
                $currentFailing > $previousFailing => 'regressing',
                default => 'stable',
            },
            'points' => ValidationRun::query()
                ->where('command_name', 'healthcheck:validate-data')
                ->where('scope', $selectedValidationRun->scope)
                ->latest('id')
                ->limit(6)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (ValidationRun $run) => [
                    'id' => $run->id,
                    'failing' => (int) (($run->summary ?? [])['failing'] ?? 0),
                    'warning' => (int) (($run->summary ?? [])['warning'] ?? 0),
                    'passing' => (int) (($run->summary ?? [])['passing'] ?? 0),
                    'completed_at' => $run->completed_at?->toDateTimeString(),
                ]),
        ];
    }

    /**
     * @return array{total:int,decisions:array<string, int>,classifications:array<string, int>,enforcement:array{enabled:bool,mode:string},needs_attention:array<int, array<string, mixed>>}
     */
    protected function aiPublishingReview(?string $sport): array
    {
        $enforcement = $this->aiPublishingEnforcement();
        $analyses = SportsAiPredictionAnalysis::query()
            ->when($sport, fn ($query) => $query->where('sport', $sport))
            ->whereDate('as_of_date', now()->toDateString())
            ->latest('id')
            ->limit(100)
            ->get();

        $decisions = [];
        $classifications = [];
        $needsAttention = [];

        foreach ($analyses as $analysis) {
            $guardrail = data_get($analysis->metadata, 'shadow_agents.publishing_guardrail', []);
            $decision = (string) data_get($guardrail, 'decision', 'unknown');
            $classification = (string) data_get($guardrail, 'publishable_classification', 'unknown');

            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;
            $classifications[$classification] = ($classifications[$classification] ?? 0) + 1;

            if (! in_array($decision, ['downgrade', 'hold', 'block'], true)) {
                continue;
            }

            $needsAttention[] = [
                'sport' => strtoupper((string) $analysis->sport),
                'matchup' => (string) data_get($analysis->raw_payload, 'game.matchup', 'Game '.$analysis->game_id),
                'decision' => $decision,
                'publishable_classification' => $classification,
                'freshness_status' => (string) data_get($analysis->metadata, 'shadow_agents.data_freshness.freshness_status', 'unknown'),
                'market_status' => (string) data_get($analysis->metadata, 'shadow_agents.market_readiness.market_status', 'unknown'),
                'model_status' => (string) data_get($analysis->metadata, 'shadow_agents.model_audit.model_status', 'unknown'),
                'summary' => (string) data_get($guardrail, 'summary', ''),
                'required_actions' => array_values(array_filter(array_map(
                    'strval',
                    (array) data_get($guardrail, 'required_actions', [])
                ))),
            ];
        }

        return [
            'total' => $analyses->count(),
            'decisions' => $decisions,
            'classifications' => $classifications,
            'enforcement' => $enforcement,
            'needs_attention' => array_slice($needsAttention, 0, 8),
        ];
    }

    /**
     * @return array{enabled:bool,mode:string}
     */
    protected function aiPublishingEnforcement(): array
    {
        $enabled = (bool) config('ai.features.publishing_guardrail_review.enforced', false);

        return [
            'enabled' => $enabled,
            'mode' => $enabled ? 'enforced' : 'shadow',
        ];
    }

    /**
     * @return array{days:int,total:int,changed_count:int,changed_rate:float,decisions:array<string, int>,changed_rows:array<int, array<string, mixed>>}
     */
    protected function aiPublishingTrend(?string $sport): array
    {
        $days = 7;
        $from = now()->subDays($days - 1)->toDateString();
        $to = now()->toDateString();

        $analyses = SportsAiPredictionAnalysis::query()
            ->when($sport, fn ($query) => $query->where('sport', $sport))
            ->whereDate('as_of_date', '>=', $from)
            ->whereDate('as_of_date', '<=', $to)
            ->latest('as_of_date')
            ->latest('id')
            ->limit(300)
            ->get();

        $decisions = [];
        $changedRows = [];

        foreach ($analyses as $analysis) {
            $guardrail = data_get($analysis->metadata, 'shadow_agents.publishing_guardrail', []);
            $decision = (string) data_get($guardrail, 'decision', 'missing');
            $guardrailClassification = (string) data_get($guardrail, 'publishable_classification', 'missing');
            $savedClassification = (string) $analysis->bet_classification;

            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;

            if ($guardrailClassification === 'missing' || $guardrailClassification === $savedClassification) {
                continue;
            }

            $changedRows[] = [
                'date' => $analysis->as_of_date?->toDateString(),
                'sport' => strtoupper((string) $analysis->sport),
                'matchup' => (string) data_get($analysis->raw_payload, 'game.matchup', 'Game '.$analysis->game_id),
                'decision' => $decision,
                'saved_classification' => $savedClassification,
                'guardrail_classification' => $guardrailClassification,
                'recommendation' => (string) $analysis->recommendation,
            ];
        }

        $changedCount = count($changedRows);

        return [
            'days' => $days,
            'total' => $analyses->count(),
            'changed_count' => $changedCount,
            'changed_rate' => $analyses->isEmpty() ? 0.0 : round($changedCount / $analyses->count(), 4),
            'decisions' => $decisions,
            'changed_rows' => array_slice($changedRows, 0, 8),
        ];
    }
}
