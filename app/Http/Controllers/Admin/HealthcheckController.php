<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\HealthcheckSummaryResource;
use App\Models\Healthcheck;
use App\Models\ValidationRun;
use App\Services\CommandHeartbeatService;
use App\Support\SportCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HealthcheckController extends Controller
{
    /**
     * @var array<string, array<string, string|null>>
     */
    private const CHECK_COMMANDS = [
        'heartbeat_sync' => [
            'nba' => 'espn:sync-nba-current',
            'nfl' => 'espn:sync-nfl-current',
            'cbb' => 'espn:sync-cbb-current',
            'wcbb' => 'espn:sync-wcbb-current',
            'cfb' => 'espn:sync-cfb-current',
            'wnba' => 'espn:sync-wnba-current',
            'mlb' => 'espn:sync-mlb-games-scoreboard',
        ],
        'heartbeat_prediction_pipeline' => [
            'nba' => 'nba:generate-predictions',
            'nfl' => 'nfl:generate-predictions',
            'cbb' => 'cbb:generate-predictions',
            'wcbb' => 'wcbb:generate-predictions',
            'mlb' => 'mlb:generate-predictions',
            'cfb' => null,
            'wnba' => null,
        ],
        'heartbeat_model_pipeline' => [
            'nba' => 'nba:calculate-elo',
            'nfl' => 'nfl:calculate-elo',
            'cbb' => 'cbb:calculate-elo',
            'wcbb' => 'wcbb:calculate-elo',
            'mlb' => 'mlb:calculate-elo',
            'cfb' => 'cfb:calculate-elo',
            'wnba' => 'wnba:calculate-elo',
        ],
        'heartbeat_odds' => [
            'nba' => 'nba:sync-odds',
            'nfl' => 'nfl:sync-odds',
            'cbb' => 'cbb:sync-odds',
            'wcbb' => 'wcbb:sync-odds',
            'cfb' => 'cfb:sync-odds',
            'wnba' => 'wnba:sync-odds',
            'mlb' => 'mlb:sync-odds',
        ],
        'heartbeat_player_props' => [
            'nba' => 'nba:sync-player-props',
            'nfl' => 'nfl:sync-player-props',
            'cbb' => 'cbb:sync-player-props',
            'mlb' => 'mlb:sync-player-props',
            'wcbb' => null,
            'cfb' => null,
            'wnba' => null,
        ],
        'heartbeat_live_scoreboard' => [
            'nba' => 'espn:sync-nba-games-scoreboard',
            'cbb' => 'espn:sync-cbb-games-scoreboard',
            'wcbb' => 'espn:sync-wcbb-games-scoreboard',
            'mlb' => 'espn:sync-mlb-games-scoreboard',
            'wnba' => 'espn:sync-wnba-games-scoreboard',
            'nfl' => 'espn:sync-nfl-games-scoreboard',
            'cfb' => 'espn:sync-cfb-games-scoreboard',
        ],
    ];

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

    public function __construct(private readonly CommandHeartbeatService $commandHeartbeatService) {}

    public function sync(Request $request): RedirectResponse
    {
        $sport = $request->input('sport');
        $checkType = $request->input('check_type');

        if (! $sport || ! $checkType) {
            return $this->backError('Sport and check type are required.');
        }

        try {
            $command = $this->getCommandForCheck($sport, $checkType);

            if (! $command) {
                return $this->backError("No sync command available for {$sport} {$checkType}.");
            }

            Artisan::call($command);
            $this->commandHeartbeatService->recordSuccess($command, $sport, 'manual');

            return $this->backSuccess("Successfully ran {$command}. Re-run health checks to see updated status.");
        } catch (\Exception $e) {
            if ($sport) {
                $this->commandHeartbeatService->recordFailure('manual:'.$checkType, $sport, 'manual', $e->getMessage());
            }

            return $this->backError('Failed to sync data: '.$e->getMessage());
        }
    }

    protected function getCommandForCheck(string $sport, string $checkType): ?string
    {
        if (isset(self::CHECK_COMMANDS[$checkType])) {
            $command = self::CHECK_COMMANDS[$checkType][$sport] ?? null;
            if (! $command) {
                return null;
            }

            if ($checkType === 'heartbeat_live_scoreboard' || ($checkType === 'heartbeat_sync' && str_contains($command, 'scoreboard'))) {
                return $command.' '.now()->format('Ymd');
            }

            return $command;
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

        return $recommendedAction;
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
}
