<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\HealthcheckSummaryResource;
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
        'sync_current' => [
            'nba' => 'espn:sync-nba-current',
            'nfl' => 'espn:sync-nfl-current',
            'cbb' => 'espn:sync-cbb-current',
            'wcbb' => 'espn:sync-wcbb-current',
            'cfb' => 'espn:sync-cfb-current',
            'wnba' => 'espn:sync-wnba-current',
            'mlb' => null,
        ],
        'generate_predictions' => [
            'nba' => 'nba:generate-predictions',
            'nfl' => 'nfl:generate-predictions',
            'cbb' => 'cbb:generate-predictions',
            'wcbb' => 'wcbb:generate-predictions',
            'mlb' => 'mlb:generate-predictions',
        ],
        'calculate_elo' => [
            'nba' => 'nba:calculate-elo',
            'nfl' => 'nfl:calculate-elo',
            'cbb' => 'cbb:calculate-elo',
            'wcbb' => 'wcbb:calculate-elo',
            'mlb' => 'mlb:calculate-elo',
        ],
        'calculate_team_metrics' => [
            'nba' => 'nba:calculate-team-metrics',
            'cbb' => 'cbb:calculate-team-metrics',
            'wcbb' => 'wcbb:calculate-team-metrics',
            'mlb' => 'mlb:calculate-team-metrics',
        ],
        'sync_team_schedules' => [
            'cbb' => 'espn:sync-cbb-all-team-schedules',
        ],
        'live_scoreboard_sync' => [
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

        // Get the latest check for each sport/check_type combination
        $latestChecks = Healthcheck::query()
            ->select('healthchecks.*')
            ->whereIn('id', function ($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('healthchecks')
                    ->groupBy('sport', 'check_type');
            })
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
            ],
        ]);
    }

    public function run(Request $request): \Illuminate\Http\RedirectResponse
    {
        $sport = $request->input('sport');

        try {
            $exitCode = Artisan::call('healthcheck:run', [
                '--sport' => $sport,
            ]);

            if ($exitCode === 0) {
                return $this->backSuccess('Health checks completed successfully.');
            }

            return $this->backWarning('Health checks completed with warnings or failures. Check the results below.');
        } catch (\Exception $e) {
            return $this->backError('Failed to run health checks: '.$e->getMessage());
        }
    }

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

            return $this->backSuccess("Successfully ran {$command}. Re-run health checks to see updated status.");
        } catch (\Exception $e) {
            return $this->backError('Failed to sync data: '.$e->getMessage());
        }
    }

    protected function getCommandForCheck(string $sport, string $checkType): ?string
    {
        $commandType = match ($checkType) {
            'data_freshness', 'missing_games' => 'sync_current',
            'stale_predictions' => 'generate_predictions',
            'elo_status' => 'calculate_elo',
            'team_metrics' => 'calculate_team_metrics',
            'team_schedules' => 'sync_team_schedules',
            'live_scoreboard_sync' => 'live_scoreboard_sync',
            default => null,
        };

        if (! $commandType) {
            return null;
        }

        $command = self::CHECK_COMMANDS[$commandType][$sport] ?? null;
        if (! $command) {
            return null;
        }

        if ($commandType === 'live_scoreboard_sync') {
            return $command.' '.now()->format('Ymd');
        }

        return $command;
    }
}
