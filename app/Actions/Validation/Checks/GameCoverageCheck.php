<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GameCoverageCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $teamsTable = $tables['teams'] ?? null;
        $gamesTable = $tables['games'] ?? null;

        if (! $teamsTable || ! $gamesTable || ! Schema::hasTable($teamsTable) || ! Schema::hasTable($gamesTable)) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $expectedPerDay = (int) ($profile['expected_games_per_day'] ?? 0);
        $season = (int) now()->year;
        $inSeason = in_array((int) now()->month, (array) ($profile['active_months'] ?? []), true);

        $totalTeams = DB::table($teamsTable)->count();

        if ($totalTeams === 0) {
            return [
                'check_type' => 'validation_game_coverage',
                'status' => 'failing',
                'message' => 'No teams found in database.',
                'metadata' => [
                    'total_teams' => 0,
                    'teams_with_games' => 0,
                    'teams_missing_games' => 0,
                ],
            ];
        }

        $homeTeamIds = DB::table($gamesTable)
            ->where('season', $season)
            ->whereNotNull('home_team_id')
            ->pluck('home_team_id');
        $awayTeamIds = DB::table($gamesTable)
            ->where('season', $season)
            ->whereNotNull('away_team_id')
            ->pluck('away_team_id');

        $teamsWithGamesIds = $homeTeamIds
            ->merge($awayTeamIds)
            ->unique()
            ->values();

        $teamsWithGames = $teamsWithGamesIds->count();
        $missingTeams = max($totalTeams - $teamsWithGames, 0);

        $upcomingGames = DB::table($gamesTable)
            ->where('game_date', '>=', now()->startOfDay())
            ->where('game_date', '<=', now()->addDays($windowDays))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
            ->count();

        $missingPct = $totalTeams > 0 ? $missingTeams / $totalTeams : 1.0;
        $warnPct = (float) config('validation.thresholds.game_coverage.missing_teams_warn_pct', 0.0);
        $failPct = (float) config('validation.thresholds.game_coverage.missing_teams_fail_pct', 0.05);
        $minUpcomingFactor = (float) config('validation.thresholds.game_coverage.min_upcoming_games_factor', 0.5);
        $expectedUpcoming = $expectedPerDay * $windowDays;
        $minUpcoming = (int) floor($expectedUpcoming * $minUpcomingFactor);

        $status = 'passing';
        $message = "Team game coverage looks healthy. {$teamsWithGames}/{$totalTeams} teams have games this season.";

        if ($missingPct >= $failPct) {
            $status = 'failing';
            $message = "{$missingTeams}/{$totalTeams} teams are missing games this season.";
        } elseif ($missingPct > $warnPct) {
            $status = 'warning';
            $message = "{$missingTeams}/{$totalTeams} teams are missing games this season.";
        }

        if ($inSeason && $upcomingGames < $minUpcoming) {
            $status = $status === 'failing' ? 'failing' : 'warning';
            $message .= " Upcoming game volume is low ({$upcomingGames} in {$windowDays} days).";
        }

        return [
            'check_type' => 'validation_game_coverage',
            'status' => $status,
            'message' => $message,
            'metadata' => [
                'season' => $season,
                'in_season' => $inSeason,
                'total_teams' => $totalTeams,
                'teams_with_games' => $teamsWithGames,
                'teams_missing_games' => $missingTeams,
                'upcoming_games' => $upcomingGames,
                'expected_upcoming_games' => $expectedUpcoming,
            ],
        ];
    }
}
