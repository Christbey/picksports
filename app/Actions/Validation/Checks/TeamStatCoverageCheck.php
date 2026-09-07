<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Support\MlbRegularSeasonWindow;
use Illuminate\Support\Collection;
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

        $stageContext = app(SeasonStageService::class)->context($sport);
        $season = (int) ($stageContext->season ?? now()->year);
        $eligibleTeamIds = $this->eligibleTeamIds($sport, $teamsTable, $season);
        $leagueTeams = $eligibleTeamIds->count();

        if ($leagueTeams === 0) {
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

        $analyticsTypes = collect((array) config("{$sport}.season.analytics_types", []))
            ->map(fn (mixed $type): int|string => is_numeric($type) ? (int) $type : (string) $type)
            ->unique()
            ->values()
            ->all();
        $filterAnalyticsTypes = $analyticsTypes !== [] && Schema::hasColumn($gamesTable, 'season_type');

        $teamsWithStatsQuery = DB::table($teamStatsTable)
            ->join($gamesTable, "{$teamStatsTable}.game_id", '=', "{$gamesTable}.id")
            ->where("{$gamesTable}.season", $season);

        if ($filterAnalyticsTypes) {
            $teamsWithStatsQuery->whereIn("{$gamesTable}.season_type", $analyticsTypes);
        }

        $teamsWithStatsIds = $teamsWithStatsQuery
            ->distinct()
            ->pluck("{$teamStatsTable}.team_id");

        $completedGamesQuery = DB::table($gamesTable)
            ->where('season', $season)
            ->whereIn('status', ['STATUS_FINAL', 'final', 'completed']);

        if ($filterAnalyticsTypes) {
            $completedGamesQuery->whereIn('season_type', $analyticsTypes);
        }

        if ($sport === 'mlb') {
            if (($openerDate = MlbRegularSeasonWindow::openerDate($season)) !== null && Schema::hasColumn($gamesTable, 'game_date')) {
                $completedGamesQuery->whereDate('game_date', '>=', $openerDate);
            }
        }

        $completedGames = (clone $completedGamesQuery)->count();
        $completedGameIds = (clone $completedGamesQuery)->pluck('id');
        $teamStatsByGame = DB::table($teamStatsTable)
            ->select('game_id', DB::raw('COUNT(DISTINCT team_id) as team_stats_count'))
            ->groupBy('game_id');

        $completedGamesMissingFullTeamStats = (clone $completedGamesQuery)
            ->leftJoinSub($teamStatsByGame, 'team_stats_by_game', "{$gamesTable}.id", '=', 'team_stats_by_game.game_id')
            ->where(fn ($query) => $query
                ->whereNull('team_stats_by_game.team_stats_count')
                ->orWhere('team_stats_by_game.team_stats_count', '<', 2))
            ->count();

        $sampleColumns = ["{$gamesTable}.id"];
        foreach (['espn_event_id', 'short_name', 'name', 'game_date'] as $column) {
            if (Schema::hasColumn($gamesTable, $column)) {
                $sampleColumns[] = "{$gamesTable}.{$column}";
            }
        }
        $sampleColumns[] = DB::raw('COALESCE(team_stats_by_game.team_stats_count, 0) as team_stats_count');

        $missingStatsSampleGames = (clone $completedGamesQuery)
            ->leftJoinSub($teamStatsByGame, 'team_stats_by_game', "{$gamesTable}.id", '=', 'team_stats_by_game.game_id')
            ->where(fn ($query) => $query
                ->whereNull('team_stats_by_game.team_stats_count')
                ->orWhere('team_stats_by_game.team_stats_count', '<', 2))
            ->select($sampleColumns)
            ->when(
                Schema::hasColumn($gamesTable, 'game_date'),
                fn ($query) => $query->orderBy("{$gamesTable}.game_date"),
                fn ($query) => $query->orderBy("{$gamesTable}.id"),
            )
            ->limit(5)
            ->get()
            ->map(fn (object $game): array => [
                'game_id' => (int) $game->id,
                'espn_event_id' => $game->espn_event_id ?? null,
                'matchup' => ($game->short_name ?? null) ?: ($game->name ?? null),
                'game_date' => isset($game->game_date) ? (string) $game->game_date : null,
                'team_stats_count' => (int) $game->team_stats_count,
                'reasons' => ['missing_full_team_stats'],
            ])
            ->all();

        if ($completedGames === 0) {
            $teamsWithStats = $teamsWithStatsIds->unique()->intersect($eligibleTeamIds)->count();

            return [
                'check_type' => 'validation_team_stat_coverage',
                'status' => 'passing',
                'message' => "No completed analytics-eligible {$sport} games found for {$season}; current-season team stats are not expected yet.",
                'metadata' => [
                    'season' => $season,
                    'stage' => $stageContext->stage,
                    'stage_group' => $stageContext->stageGroup,
                    'analytics_season_types' => $analyticsTypes,
                    'total_teams' => $leagueTeams,
                    'teams_with_stats' => $teamsWithStats,
                    'teams_missing_stats' => max($leagueTeams - $teamsWithStats, 0),
                    'completed_games' => $completedGames,
                ],
            ];
        }

        $teamsWithCompletedGames = DB::table($gamesTable)
            ->whereIn('id', $completedGameIds)
            ->get(['home_team_id', 'away_team_id'])
            ->flatMap(fn (object $game): array => [$game->home_team_id, $game->away_team_id])
            ->filter()
            ->unique()
            ->intersect($eligibleTeamIds)
            ->values();
        $totalTeams = $teamsWithCompletedGames->count();
        $teamsWithStats = $teamsWithStatsIds->unique()->intersect($teamsWithCompletedGames)->count();
        $missingTeams = max($totalTeams - $teamsWithStats, 0);
        $missingPct = $totalTeams > 0 ? $missingTeams / $totalTeams : 0.0;

        $warnPct = (float) config('validation.thresholds.team_stat_coverage.missing_teams_warn_pct', 0.0);
        $failPct = (float) config('validation.thresholds.team_stat_coverage.missing_teams_fail_pct', 0.05);

        $status = 'passing';
        $message = "Team stat coverage looks healthy. {$teamsWithStats}/{$totalTeams} teams with completed games have stats this season.";

        if ($completedGamesMissingFullTeamStats > 0) {
            $status = 'failing';
            $message = "{$completedGamesMissingFullTeamStats}/{$completedGames} completed {$sport} game(s) are missing one or both team stat rows.";
        } elseif ($missingPct >= $failPct) {
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
                'analytics_season_types' => $analyticsTypes,
                'league_teams' => $leagueTeams,
                'total_teams' => $totalTeams,
                'teams_with_stats' => $teamsWithStats,
                'teams_missing_stats' => $missingTeams,
                'completed_games' => $completedGames,
                'completed_games_missing_full_team_stats' => $completedGamesMissingFullTeamStats,
                'sample_games' => $missingStatsSampleGames,
            ],
            'recommended_action' => "espn:sync-{$sport}-game-details",
        ];
    }

    private function eligibleTeamIds(string $sport, string $teamsTable, int $season): Collection
    {
        if (
            $sport === 'cfb'
            && Schema::hasTable('cfb_team_season_affiliations')
            && Schema::hasColumn('cfb_team_season_affiliations', 'subdivision')
        ) {
            $ids = DB::table($teamsTable)
                ->join('cfb_team_season_affiliations', function ($join) use ($teamsTable, $season): void {
                    $join->on('cfb_team_season_affiliations.team_id', '=', "{$teamsTable}.id")
                        ->where('cfb_team_season_affiliations.season', '=', $season)
                        ->where('cfb_team_season_affiliations.subdivision', '=', config('cfb.teams.divisions.fbs', 'FBS'));
                })
                ->pluck("{$teamsTable}.id")
                ->unique()
                ->values();

            if ($ids->isNotEmpty()) {
                return $ids;
            }
        }

        return DB::table($teamsTable)->pluck('id')->unique()->values();
    }
}
