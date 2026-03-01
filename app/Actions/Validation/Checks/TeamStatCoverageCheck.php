<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeamStatCoverageCheck implements ValidationCheck
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
        $teamStatsTable = $tables['team_stats'] ?? null;

        if (
            ! $teamsTable || ! $gamesTable || ! $teamStatsTable
            || ! Schema::hasTable($teamsTable) || ! Schema::hasTable($gamesTable) || ! Schema::hasTable($teamStatsTable)
        ) {
            return null;
        }

        $season = (int) now()->year;
        $totalTeams = DB::table($teamsTable)->count();

        if ($totalTeams === 0) {
            return [
                'check_type' => 'validation_team_stat_coverage',
                'status' => 'failing',
                'message' => 'No teams found in database.',
                'metadata' => [
                    'total_teams' => 0,
                    'teams_with_stats' => 0,
                    'teams_missing_stats' => 0,
                ],
            ];
        }

        $teamsWithStatsIds = DB::table($teamStatsTable)
            ->join($gamesTable, "{$teamStatsTable}.game_id", '=', "{$gamesTable}.id")
            ->where("{$gamesTable}.season", $season)
            ->distinct()
            ->pluck("{$teamStatsTable}.team_id");

        $teamsWithStats = $teamsWithStatsIds->unique()->count();
        $missingTeams = max($totalTeams - $teamsWithStats, 0);
        $missingPct = $totalTeams > 0 ? $missingTeams / $totalTeams : 1.0;

        $warnPct = (float) config('validation.thresholds.team_stat_coverage.missing_teams_warn_pct', 0.0);
        $failPct = (float) config('validation.thresholds.team_stat_coverage.missing_teams_fail_pct', 0.05);

        $status = 'passing';
        $message = "Team stat coverage looks healthy. {$teamsWithStats}/{$totalTeams} teams have stats this season.";

        if ($missingPct >= $failPct) {
            $status = 'failing';
            $message = "{$missingTeams}/{$totalTeams} teams are missing team stats this season.";
        } elseif ($missingPct > $warnPct) {
            $status = 'warning';
            $message = "{$missingTeams}/{$totalTeams} teams are missing team stats this season.";
        }

        return [
            'check_type' => 'validation_team_stat_coverage',
            'status' => $status,
            'message' => $message,
            'metadata' => [
                'season' => $season,
                'total_teams' => $totalTeams,
                'teams_with_stats' => $teamsWithStats,
                'teams_missing_stats' => $missingTeams,
            ],
        ];
    }
}
