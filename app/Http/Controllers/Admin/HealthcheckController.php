<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Healthcheck;
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
            ->get()
            ->map(function ($check) {
                return [
                    'id' => $check->id,
                    'sport' => $check->sport,
                    'check_type' => $check->check_type,
                    'status' => $check->status,
                    'message' => $check->message,
                    'metadata' => $check->metadata,
                    'checked_at' => $check->checked_at?->toDateTimeString(),
                ];
            });

        // Group checks by sport
        $checksBySport = $latestChecks->groupBy('sport');

        // Get overall status counts
        $statusCounts = $latestChecks->groupBy('status')->map->count();

        // Get sport filter options
        $sports = ['mlb', 'nba', 'nfl', 'cbb', 'cfb', 'wcbb', 'wnba'];

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
                return back()->with('success', 'Health checks completed successfully.');
            }

            return back()->with('warning', 'Health checks completed with warnings or failures. Check the results below.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to run health checks: '.$e->getMessage());
        }
    }

    public function sync(Request $request): \Illuminate\Http\RedirectResponse
    {
        $sport = $request->input('sport');
        $checkType = $request->input('check_type');

        if (! $sport || ! $checkType) {
            return back()->with('error', 'Sport and check type are required.');
        }

        try {
            $command = $this->getCommandForCheck($sport, $checkType);

            if (! $command) {
                return back()->with('error', "No sync command available for {$sport} {$checkType}.");
            }

            Artisan::call($command);

            return back()->with('success', "Successfully ran {$command}. Re-run health checks to see updated status.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to sync data: '.$e->getMessage());
        }
    }

    protected function getCommandForCheck(string $sport, string $checkType): ?string
    {
        return match ($checkType) {
            'data_freshness', 'missing_games' => $this->getSyncCommand($sport),
            'stale_predictions' => $this->getPredictionCommand($sport),
            'elo_status' => $this->getEloCommand($sport),
            'team_metrics' => $this->getTeamMetricsCommand($sport),
            'team_schedules' => $this->getTeamSchedulesCommand($sport),
            'live_scoreboard_sync' => $this->getLiveScoreboardCommand($sport),
            default => null,
        };
    }

    protected function getSyncCommand(string $sport): ?string
    {
        return match ($sport) {
            'nba' => 'espn:sync-nba-current',
            'nfl' => 'espn:sync-nfl-current',
            'cbb' => 'espn:sync-cbb-current',
            'wcbb' => 'espn:sync-wcbb-current',
            'cfb' => 'espn:sync-cfb-current',
            'wnba' => 'espn:sync-wnba-current',
            'mlb' => null, // No sync-current command for MLB
            default => null,
        };
    }

    protected function getPredictionCommand(string $sport): ?string
    {
        return match ($sport) {
            'nba' => 'nba:generate-predictions',
            'nfl' => 'nfl:generate-predictions',
            'cbb' => 'cbb:generate-predictions',
            'wcbb' => 'wcbb:generate-predictions',
            'mlb' => 'mlb:generate-predictions',
            default => null,
        };
    }

    protected function getEloCommand(string $sport): ?string
    {
        return match ($sport) {
            'nba' => 'nba:calculate-elo',
            'nfl' => 'nfl:calculate-elo',
            'cbb' => 'cbb:calculate-elo',
            'wcbb' => 'wcbb:calculate-elo',
            'mlb' => 'mlb:calculate-elo',
            default => null,
        };
    }

    protected function getTeamMetricsCommand(string $sport): ?string
    {
        return match ($sport) {
            'nba' => 'nba:calculate-team-metrics',
            'cbb' => 'cbb:calculate-team-metrics',
            'wcbb' => 'wcbb:calculate-team-metrics',
            'mlb' => 'mlb:calculate-team-metrics',
            default => null,
        };
    }

    protected function getTeamSchedulesCommand(string $sport): ?string
    {
        return match ($sport) {
            'cbb' => 'espn:sync-cbb-all-team-schedules',
            default => null,
        };
    }

    protected function getLiveScoreboardCommand(string $sport): ?string
    {
        return match ($sport) {
            'nba' => 'espn:sync-nba-games-scoreboard '.date('Ymd'),
            'cbb' => 'espn:sync-cbb-games-scoreboard '.date('Ymd'),
            'wcbb' => 'espn:sync-wcbb-games-scoreboard '.date('Ymd'),
            'mlb' => 'espn:sync-mlb-games-scoreboard '.date('Ymd'),
            'wnba' => 'espn:sync-wnba-games-scoreboard '.date('Ymd'),
            'nfl' => 'espn:sync-nfl-games-scoreboard '.date('Ymd'),
            'cfb' => 'espn:sync-cfb-games-scoreboard '.date('Ymd'),
            default => null,
        };
    }
}
