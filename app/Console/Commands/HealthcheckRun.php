<?php

namespace App\Console\Commands;

use App\Models\Healthcheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthcheckRun extends Command
{
    protected $signature = 'healthcheck:run {--sport= : Specific sport to check (mlb, nba, nfl, cbb, cfb, wcbb, wnba)}';

    protected $description = 'Run healthchecks to monitor data sync across all sports';

    protected array $sports = ['mlb', 'nba', 'nfl', 'cbb', 'cfb', 'wcbb', 'wnba'];

    public function handle(): int
    {
        $this->info('Running healthchecks...');

        $sports = $this->option('sport') ? [$this->option('sport')] : $this->sports;

        foreach ($sports as $sport) {
            $this->line("Checking {$sport}...");

            $this->checkDataFreshness($sport);
            $this->checkMissingGames($sport);
            $this->checkTeamSchedules($sport);
            $this->checkStalePredictions($sport);
            $this->checkEloStatus($sport);
            $this->checkTeamMetrics($sport);
        }

        return $this->displayResults();
    }

    protected function checkDataFreshness(string $sport): void
    {
        $table = "{$sport}_games";

        // Check total games to detect zero-data scenario
        $totalGames = DB::table($table)->count();

        if ($totalGames === 0) {
            $this->recordCheck($sport, 'data_freshness', 'failing', 'No games found in database. Data sync may have failed.', [
                'total_games' => 0,
                'recent_games' => 0,
                'stale_games' => 0,
            ]);

            return;
        }

        // Check if any games have been updated in the last 24 hours
        $recentGames = DB::table($table)
            ->where('updated_at', '>=', now()->subHours(24))
            ->count();

        // Check if there are scheduled or in-progress games that haven't been updated recently
        // Only check today and future games, not historical games
        $staleGames = DB::table($table)
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS'])
            ->where('game_date', '>=', now()->startOfDay())
            ->where('game_date', '<=', now()->addDays(7))
            ->where('updated_at', '<', now()->subHours(24))
            ->count();

        $status = 'passing';
        $message = "Data is fresh. {$recentGames} games updated in last 24 hours.";

        if ($staleGames > 5) {
            $status = 'failing';
            $message = "{$staleGames} upcoming games haven't been updated in 24 hours.";
        } elseif ($staleGames > 0) {
            $status = 'warning';
            $message = "{$staleGames} upcoming games haven't been updated in 24 hours.";
        }

        $this->recordCheck($sport, 'data_freshness', $status, $message, [
            'total_games' => $totalGames,
            'recent_games' => $recentGames,
            'stale_games' => $staleGames,
        ]);
    }

    protected function checkMissingGames(string $sport): void
    {
        $table = "{$sport}_games";

        // Count scheduled games for next 7 days
        $upcomingGames = DB::table($table)
            ->where('game_date', '>=', now()->startOfDay())
            ->where('game_date', '<=', now()->addDays(7))
            ->where('status', 'STATUS_SCHEDULED')
            ->count();

        // Expected minimum games per day (rough estimates per sport)
        $expectedGamesPerDay = match ($sport) {
            'mlb' => 10, // 30 teams, ~15 games per day
            'nba' => 5,  // 30 teams, typically 5-15 games per day
            'nfl' => 1,  // 32 teams, varies by day (Sunday has most)
            'cbb', 'wcbb' => 20, // Hundreds of teams, many games
            'cfb' => 10, // Games mostly on Saturdays
            'wnba' => 2, // 12 teams, 2-6 games per day
            default => 5,
        };

        // Expected total for 7 days
        $expectedTotal = $expectedGamesPerDay * 7;

        $status = 'passing';
        $message = "{$upcomingGames} upcoming games scheduled for next 7 days.";

        // Only check during active season (rough heuristic)
        $isActiveSeason = match ($sport) {
            'mlb' => now()->month >= 3 && now()->month <= 10,
            'nba' => now()->month >= 10 || now()->month <= 6,
            'nfl' => now()->month >= 9 || now()->month <= 2,
            'cbb', 'wcbb' => now()->month >= 11 || now()->month <= 4,
            'cfb' => now()->month >= 8 || now()->month <= 1,
            'wnba' => now()->month >= 5 && now()->month <= 9,
            default => true,
        };

        if ($isActiveSeason) {
            if ($upcomingGames === 0) {
                $status = 'failing';
                $message = "No upcoming games found during active season. Expected at least {$expectedTotal} games over 7 days.";
            } elseif ($upcomingGames < $expectedTotal * 0.5) {
                $status = 'failing';
                $message = "Only {$upcomingGames} upcoming games. Expected at least {$expectedTotal} over 7 days during active season.";
            } elseif ($upcomingGames < $expectedTotal) {
                $status = 'warning';
                $message = "Only {$upcomingGames} upcoming games. Expected around {$expectedTotal} over 7 days during active season.";
            }
        }

        $this->recordCheck($sport, 'missing_games', $status, $message, [
            'upcoming_games' => $upcomingGames,
            'expected_per_day' => $expectedGamesPerDay,
            'expected_total' => $expectedTotal,
            'is_active_season' => $isActiveSeason,
        ]);
    }

    protected function checkTeamSchedules(string $sport): void
    {
        $gamesTable = "{$sport}_games";
        $teamsTable = "{$sport}_teams";

        // Get total teams
        $totalTeams = DB::table($teamsTable)->count();

        if ($totalTeams === 0) {
            $this->recordCheck($sport, 'team_schedules', 'failing', 'No teams found in database. Cannot check team schedules.', [
                'total_teams' => 0,
                'teams_with_no_games' => 0,
                'outliers' => [],
            ]);

            return;
        }

        // Get games per team for current season
        $season = now()->year;

        // Count games per team (both home and away)
        $homeGames = DB::table($gamesTable)
            ->select('home_team_id as team_id', DB::raw('count(*) as game_count'))
            ->where('season', $season)
            ->groupBy('home_team_id');

        $awayGames = DB::table($gamesTable)
            ->select('away_team_id as team_id', DB::raw('count(*) as game_count'))
            ->where('season', $season)
            ->groupBy('away_team_id');

        $gamesCounts = DB::table(DB::raw("({$homeGames->toSql()} UNION ALL {$awayGames->toSql()}) as games"))
            ->mergeBindings($homeGames)
            ->mergeBindings($awayGames)
            ->select('team_id', DB::raw('SUM(game_count) as total_games'))
            ->groupBy('team_id')
            ->get();

        // Calculate statistics
        $counts = $gamesCounts->pluck('total_games')->toArray();

        if (empty($counts)) {
            $this->recordCheck($sport, 'team_schedules', 'failing', "No games found for season {$season}. Team schedules may not be synced.", [
                'total_teams' => $totalTeams,
                'teams_with_games' => 0,
                'teams_with_no_games' => $totalTeams,
                'outliers' => [],
            ]);

            return;
        }

        $average = array_sum($counts) / count($counts);
        $variance = array_sum(array_map(fn ($x) => ($x - $average) ** 2, $counts)) / count($counts);
        $stdDev = sqrt($variance);

        // Find teams with no games
        $teamsWithGames = $gamesCounts->pluck('team_id')->toArray();
        $teamsWithNoGames = DB::table($teamsTable)
            ->whereNotIn('id', $teamsWithGames)
            ->get(['id', 'school', 'mascot', 'espn_id']);

        // Find outliers (teams with game counts > 2 standard deviations from mean)
        $outliers = [];
        foreach ($gamesCounts as $teamGames) {
            $deviation = abs($teamGames->total_games - $average);

            if ($deviation > 2 * $stdDev) {
                $team = DB::table($teamsTable)->where('id', $teamGames->team_id)->first(['school', 'mascot', 'espn_id']);

                $outliers[] = [
                    'team' => $team ? "{$team->school} {$team->mascot}" : "Team ID {$teamGames->team_id}",
                    'espn_id' => $team->espn_id ?? null,
                    'games' => $teamGames->total_games,
                    'deviation_from_avg' => round($teamGames->total_games - $average, 1),
                    'type' => $teamGames->total_games < $average ? 'too_few' : 'too_many',
                ];
            }
        }

        // Add teams with zero games as outliers
        foreach ($teamsWithNoGames as $team) {
            $outliers[] = [
                'team' => "{$team->school} {$team->mascot}",
                'espn_id' => $team->espn_id,
                'games' => 0,
                'deviation_from_avg' => round(0 - $average, 1),
                'type' => 'no_games',
            ];
        }

        // Determine status
        $status = 'passing';
        $message = 'Team schedules look good. Average: '.round($average, 1)." games/team. {$teamsWithNoGames->count()} teams with no games, ".count($outliers).' outliers detected.';

        if ($teamsWithNoGames->count() > $totalTeams * 0.1 || count($outliers) > $totalTeams * 0.15) {
            $status = 'failing';
            $message = "Significant schedule issues. {$teamsWithNoGames->count()} teams with no games, ".count($outliers).' outliers (>2 std dev from avg '.round($average, 1).' games).';
        } elseif ($teamsWithNoGames->count() > 0 || count($outliers) > 0) {
            $status = 'warning';
            $message = "Some schedule issues. {$teamsWithNoGames->count()} teams with no games, ".count($outliers).' outliers (>2 std dev from avg '.round($average, 1).' games).';
        }

        $this->recordCheck($sport, 'team_schedules', $status, $message, [
            'total_teams' => $totalTeams,
            'teams_with_games' => count($counts),
            'teams_with_no_games' => $teamsWithNoGames->count(),
            'average_games' => round($average, 2),
            'std_dev' => round($stdDev, 2),
            'min_games' => min($counts),
            'max_games' => max($counts),
            'outliers' => $outliers,
            'season' => $season,
        ]);
    }

    protected function checkStalePredictions(string $sport): void
    {
        // Only sports with predictions
        if (! in_array($sport, ['mlb', 'nba', 'nfl', 'cbb', 'wcbb'])) {
            return;
        }

        $gamesTable = "{$sport}_games";
        $predictionsTable = "{$sport}_predictions";

        // Check if there are any games at all
        $totalGames = DB::table($gamesTable)->count();

        if ($totalGames === 0) {
            $this->recordCheck($sport, 'stale_predictions', 'failing', 'No games found in database. Cannot check predictions.', [
                'total_games' => 0,
                'stale_count' => 0,
                'missing_predictions' => 0,
            ]);

            return;
        }

        // Find completed games without predictions or with outdated predictions
        $staleCount = DB::table($gamesTable)
            ->leftJoin($predictionsTable, "{$gamesTable}.id", '=', "{$predictionsTable}.game_id")
            ->where("{$gamesTable}.status", 'STATUS_FINAL')
            ->where("{$gamesTable}.game_date", '>=', now()->subDays(7))
            ->where(function ($query) use ($predictionsTable, $gamesTable) {
                $query->whereNull("{$predictionsTable}.id")
                    ->orWhere("{$predictionsTable}.updated_at", '<', DB::raw("{$gamesTable}.updated_at"));
            })
            ->count();

        // Find scheduled games without predictions
        $missingPredictions = DB::table($gamesTable)
            ->leftJoin($predictionsTable, "{$gamesTable}.id", '=', "{$predictionsTable}.game_id")
            ->where("{$gamesTable}.status", 'STATUS_SCHEDULED')
            ->where("{$gamesTable}.game_date", '>=', now())
            ->where("{$gamesTable}.game_date", '<=', now()->addDays(3))
            ->whereNull("{$predictionsTable}.id")
            ->count();

        $status = 'passing';
        $message = 'Predictions are up to date.';

        if ($staleCount > 10 || $missingPredictions > 20) {
            $status = 'failing';
            $message = "{$staleCount} completed games with stale predictions, {$missingPredictions} upcoming games missing predictions.";
        } elseif ($staleCount > 0 || $missingPredictions > 0) {
            $status = 'warning';
            $message = "{$staleCount} completed games with stale predictions, {$missingPredictions} upcoming games missing predictions.";
        }

        $this->recordCheck($sport, 'stale_predictions', $status, $message, [
            'total_games' => $totalGames,
            'stale_count' => $staleCount,
            'missing_predictions' => $missingPredictions,
        ]);
    }

    protected function checkEloStatus(string $sport): void
    {
        $teamsTable = "{$sport}_teams";

        // Check how many teams have Elo ratings
        $teamsWithElo = DB::table($teamsTable)
            ->whereNotNull('elo_rating')
            ->where('elo_rating', '>', 0)
            ->count();

        $totalTeams = DB::table($teamsTable)->count();

        // Check when teams were last updated
        $recentlyUpdated = DB::table($teamsTable)
            ->where('updated_at', '>=', now()->subDays(2))
            ->count();

        // Handle zero-data scenario
        if ($totalTeams === 0) {
            $this->recordCheck($sport, 'elo_status', 'failing', 'No teams found in database. Data sync may have failed.', [
                'teams_with_elo' => 0,
                'total_teams' => 0,
                'recently_updated' => 0,
            ]);

            return;
        }

        $status = 'passing';
        $message = "{$teamsWithElo}/{$totalTeams} teams have Elo ratings. {$recentlyUpdated} updated in last 2 days.";

        if ($teamsWithElo < $totalTeams * 0.5) {
            $status = 'failing';
            $message = "Only {$teamsWithElo}/{$totalTeams} teams have Elo ratings.";
        } elseif ($teamsWithElo < $totalTeams) {
            $status = 'warning';
            $missingTeams = $totalTeams - $teamsWithElo;
            $message = "{$teamsWithElo}/{$totalTeams} teams have Elo ratings. {$missingTeams} teams missing.";
        }

        $this->recordCheck($sport, 'elo_status', $status, $message, [
            'teams_with_elo' => $teamsWithElo,
            'total_teams' => $totalTeams,
            'recently_updated' => $recentlyUpdated,
        ]);
    }

    protected function checkTeamMetrics(string $sport): void
    {
        // Only sports with team metrics
        if (! in_array($sport, ['mlb', 'nba', 'cbb', 'wcbb', 'wnba'])) {
            return;
        }

        $metricsTable = "{$sport}_team_metrics";
        $teamsTable = "{$sport}_teams";

        $totalTeams = DB::table($teamsTable)->count();

        if ($totalTeams === 0) {
            $this->recordCheck($sport, 'team_metrics', 'failing', 'No teams found in database. Cannot check team metrics.', [
                'total_teams' => 0,
                'teams_with_metrics' => 0,
                'recently_updated' => 0,
            ]);

            return;
        }

        // Check how many teams have metrics calculated
        $teamsWithMetrics = DB::table($metricsTable)->distinct('team_id')->count();

        // Check when metrics were last updated
        $recentlyUpdated = DB::table($metricsTable)
            ->where('updated_at', '>=', now()->subDays(3))
            ->distinct('team_id')
            ->count();

        $status = 'passing';
        $message = "{$teamsWithMetrics}/{$totalTeams} teams have metrics calculated. {$recentlyUpdated} updated in last 3 days.";

        if ($teamsWithMetrics === 0) {
            $status = 'failing';
            $message = "No team metrics found. Run {$sport}:calculate-team-metrics to generate metrics.";
        } elseif ($teamsWithMetrics < $totalTeams * 0.5) {
            $status = 'failing';
            $message = "Only {$teamsWithMetrics}/{$totalTeams} teams have metrics calculated.";
        } elseif ($teamsWithMetrics < $totalTeams) {
            $status = 'warning';
            $missingTeams = $totalTeams - $teamsWithMetrics;
            $message = "{$teamsWithMetrics}/{$totalTeams} teams have metrics. {$missingTeams} teams missing.";
        } elseif ($recentlyUpdated < $totalTeams * 0.5) {
            $status = 'warning';
            $message = "Metrics exist but may be stale. Only {$recentlyUpdated}/{$totalTeams} updated in last 3 days.";
        }

        $this->recordCheck($sport, 'team_metrics', $status, $message, [
            'total_teams' => $totalTeams,
            'teams_with_metrics' => $teamsWithMetrics,
            'recently_updated' => $recentlyUpdated,
        ]);
    }

    protected function recordCheck(string $sport, string $checkType, string $status, string $message, array $metadata = []): void
    {
        Healthcheck::create([
            'sport' => $sport,
            'check_type' => $checkType,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
            'checked_at' => now(),
        ]);

        $color = match ($status) {
            'passing' => 'green',
            'warning' => 'yellow',
            'failing' => 'red',
            default => 'white',
        };

        $this->line("  [{$checkType}] <fg={$color}>{$status}</>: {$message}");
    }

    protected function displayResults(): int
    {
        $this->newLine();
        $this->info('Healthcheck Summary:');

        $results = Healthcheck::query()
            ->where('checked_at', '>=', now()->subMinutes(5))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        foreach ($results as $result) {
            $color = match ($result->status) {
                'passing' => 'green',
                'warning' => 'yellow',
                'failing' => 'red',
                default => 'white',
            };

            $this->line("<fg={$color}>{$result->status}: {$result->count} checks</>");
        }

        $failing = Healthcheck::query()
            ->where('checked_at', '>=', now()->subMinutes(5))
            ->where('status', 'failing')
            ->count();

        return $failing > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
