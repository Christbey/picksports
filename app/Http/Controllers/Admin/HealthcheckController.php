<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\HealthcheckSummaryResource;
use App\Services\CommandHeartbeatService;
use App\Models\Healthcheck;
use App\Support\SportCatalog;
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

        return Inertia::render('Admin/Healthchecks', [
            'checks_by_sport' => $checksBySport,
            'status_counts' => $statusCounts,
            'sports' => $sports,
            'filters' => [
                'sport' => $sport,
                'view' => $view,
            ],
        ]);
    }

    public function run(Request $request): \Illuminate\Http\RedirectResponse
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

    public function sync(Request $request): \Illuminate\Http\RedirectResponse
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
        if (! isset(self::CHECK_COMMANDS[$checkType])) {
            return null;
        }

        $command = self::CHECK_COMMANDS[$checkType][$sport] ?? null;
        if (! $command) {
            return null;
        }

        if ($checkType === 'heartbeat_live_scoreboard' || ($checkType === 'heartbeat_sync' && str_contains($command, 'scoreboard'))) {
            return $command.' '.now()->format('Ymd');
        }

        return $command;
    }
}
