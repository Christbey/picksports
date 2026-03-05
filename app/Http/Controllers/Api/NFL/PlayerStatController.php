<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\NFL\PlayerStatResource;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    protected function getByGameRelations(): array
    {
        return ['player', 'team'];
    }

    protected function getByPlayerRelations(): array
    {
        return ['game.homeTeam', 'game.awayTeam'];
    }

    protected function supportsLeaderboard(): bool
    {
        return true;
    }

    protected function getLeaderboardData(Request $request): Collection
    {
        $minGames = (int) ($request->integer('min_games') ?: 4);
        $season = $request->filled('season') ? (int) $request->integer('season') : null;

        $query = PlayerStat::query()
            ->join('nfl_players', 'nfl_players.id', '=', 'nfl_player_stats.player_id')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->leftJoin('nfl_teams', 'nfl_teams.id', '=', 'nfl_players.team_id')
            ->where('nfl_games.status', config('nfl.statuses.final'))
            ->selectRaw('
                nfl_player_stats.player_id,
                COUNT(DISTINCT nfl_player_stats.game_id) as games_played,
                SUM(COALESCE(nfl_player_stats.passing_yards, 0)) as passing_yards,
                SUM(COALESCE(nfl_player_stats.rushing_yards, 0)) as rushing_yards,
                SUM(COALESCE(nfl_player_stats.receiving_yards, 0)) as receiving_yards,
                SUM(COALESCE(nfl_player_stats.passing_touchdowns, 0)) as passing_touchdowns,
                SUM(COALESCE(nfl_player_stats.rushing_touchdowns, 0)) as rushing_touchdowns,
                SUM(COALESCE(nfl_player_stats.receiving_touchdowns, 0)) as receiving_touchdowns,
                SUM(COALESCE(nfl_player_stats.receptions, 0)) as receptions,
                SUM(COALESCE(nfl_player_stats.receiving_targets, 0)) as receiving_targets,
                SUM(COALESCE(nfl_player_stats.rushing_attempts, 0)) as rushing_attempts,
                SUM(COALESCE(nfl_player_stats.passing_completions, 0)) as passing_completions,
                SUM(COALESCE(nfl_player_stats.passing_attempts, 0)) as passing_attempts,
                SUM(COALESCE(nfl_player_stats.interceptions_thrown, 0)) as interceptions_thrown,
                SUM(COALESCE(nfl_player_stats.fumbles_recovered, 0)) as fumbles_recovered,
                SUM(COALESCE(nfl_player_stats.tackles_total, 0)) as tackles_total,
                SUM(COALESCE(nfl_player_stats.sacks, 0)) as sacks,
                SUM(COALESCE(nfl_player_stats.interceptions, 0)) as defensive_interceptions,
                SUM(COALESCE(nfl_player_stats.passes_defended, 0)) as passes_defended,
                SUM(COALESCE(nfl_player_stats.field_goals_made, 0)) as field_goals_made,
                SUM(COALESCE(nfl_player_stats.field_goals_attempted, 0)) as field_goals_attempted,
                SUM(COALESCE(nfl_player_stats.extra_points_made, 0)) as extra_points_made,
                SUM(COALESCE(nfl_player_stats.extra_points_attempted, 0)) as extra_points_attempted,
                nfl_players.id as player_ref_id,
                nfl_players.full_name,
                nfl_players.headshot_url,
                nfl_players.position,
                nfl_players.jersey_number,
                nfl_teams.id as team_id,
                nfl_teams.name as team_name,
                nfl_teams.location as team_location,
                nfl_teams.abbreviation as team_abbreviation
            ')
            ->groupBy([
                'nfl_player_stats.player_id',
                'nfl_players.id',
                'nfl_players.full_name',
                'nfl_players.headshot_url',
                'nfl_players.position',
                'nfl_players.jersey_number',
                'nfl_teams.id',
                'nfl_teams.name',
                'nfl_teams.location',
                'nfl_teams.abbreviation',
            ])
            ->havingRaw('COUNT(DISTINCT nfl_player_stats.game_id) >= ?', [$minGames]);

        if ($season !== null) {
            $query->where('nfl_games.season', $season);
        }

        return $query
            ->orderByDesc(DB::raw('(SUM(COALESCE(nfl_player_stats.passing_yards, 0)) + SUM(COALESCE(nfl_player_stats.rushing_yards, 0)) + SUM(COALESCE(nfl_player_stats.receiving_yards, 0))) / NULLIF(COUNT(DISTINCT nfl_player_stats.game_id), 0)'))
            ->limit(1000)
            ->get()
            ->map(function ($row): array {
                $games = max(1, (int) $row->games_played);
                $passingYards = (float) $row->passing_yards;
                $rushingYards = (float) $row->rushing_yards;
                $receivingYards = (float) $row->receiving_yards;
                $totalYards = $passingYards + $rushingYards + $receivingYards;
                $touchdowns = (float) $row->passing_touchdowns + (float) $row->rushing_touchdowns + (float) $row->receiving_touchdowns;
                $touches = (float) $row->rushing_attempts + (float) $row->receiving_targets;

                $compPct = ((float) $row->passing_attempts) > 0
                    ? (((float) $row->passing_completions / (float) $row->passing_attempts) * 100)
                    : 0.0;
                $catchPct = ((float) $row->receiving_targets) > 0
                    ? (((float) $row->receptions / (float) $row->receiving_targets) * 100)
                    : 0.0;
                $yardsPerCarry = ((float) $row->rushing_attempts) > 0
                    ? ((float) $row->rushing_yards / (float) $row->rushing_attempts)
                    : 0.0;
                $fieldGoalPct = ((float) $row->field_goals_attempted) > 0
                    ? (((float) $row->field_goals_made / (float) $row->field_goals_attempted) * 100)
                    : 0.0;
                $extraPointPct = ((float) $row->extra_points_attempted) > 0
                    ? (((float) $row->extra_points_made / (float) $row->extra_points_attempted) * 100)
                    : 0.0;
                $attempts = (float) $row->passing_attempts;
                $completions = (float) $row->passing_completions;
                $interceptionsThrown = (float) $row->interceptions_thrown;
                $passerRating = 0.0;
                if ($attempts > 0) {
                    $a = (($completions / $attempts) - 0.3) * 5;
                    $b = (($passingYards / $attempts) - 3) * 0.25;
                    $c = (((float) $row->passing_touchdowns / $attempts) * 20);
                    $d = 2.375 - (($interceptionsThrown / $attempts) * 25);
                    $a = max(0.0, min(2.375, $a));
                    $b = max(0.0, min(2.375, $b));
                    $c = max(0.0, min(2.375, $c));
                    $d = max(0.0, min(2.375, $d));
                    $passerRating = (($a + $b + $c + $d) / 6) * 100;
                }

                return [
                    'player_id' => (int) $row->player_id,
                    'player' => [
                        'id' => (int) $row->player_ref_id,
                        'full_name' => $row->full_name,
                        'headshot_url' => $row->headshot_url,
                        'position' => $row->position,
                        'jersey_number' => $row->jersey_number,
                        'team' => $row->team_id ? [
                            'id' => (int) $row->team_id,
                            'name' => $row->team_name,
                            'display_name' => trim("{$row->team_location} {$row->team_name}"),
                            'abbreviation' => $row->team_abbreviation,
                        ] : null,
                    ],
                    'games_played' => (int) $row->games_played,
                    // Reusing generic leaderboard fields for NFL
                    'points_per_game' => round($totalYards / $games, 1),
                    'rebounds_per_game' => round($touchdowns / $games, 2),
                    'assists_per_game' => round((float) $row->receptions / $games, 2),
                    'steals_per_game' => round($passingYards / $games, 1),
                    'blocks_per_game' => round($rushingYards / $games, 1),
                    'minutes_per_game' => round($touches / $games, 1),
                    'field_goal_percentage' => round($compPct, 1),
                    'three_point_percentage' => round($catchPct, 1),
                    'free_throw_percentage' => round($yardsPerCarry, 1),
                    'passing_yards_per_game' => round($passingYards / $games, 1),
                    'passing_touchdowns_per_game' => round((float) $row->passing_touchdowns / $games, 2),
                    'completion_percentage' => round($compPct, 1),
                    'interceptions_thrown_per_game' => round($interceptionsThrown / $games, 2),
                    'passing_yards_total' => (int) round($passingYards),
                    'passing_touchdowns_total' => (int) round((float) $row->passing_touchdowns),
                    'interceptions_thrown_total' => (int) round($interceptionsThrown),
                    'passing_attempts_total' => (int) round($attempts),
                    'passing_completions_total' => (int) round($completions),
                    'qbr' => round($passerRating, 1),
                    'rushing_yards_per_game' => round($rushingYards / $games, 1),
                    'rushing_touchdowns_per_game' => round((float) $row->rushing_touchdowns / $games, 2),
                    'yards_per_carry' => round($yardsPerCarry, 1),
                    'receptions_per_game' => round((float) $row->receptions / $games, 2),
                    'receiving_yards_per_game' => round($receivingYards / $games, 1),
                    'rushing_yards_total' => (int) round($rushingYards),
                    'rushing_touchdowns_total' => (int) round((float) $row->rushing_touchdowns),
                    'rushing_attempts_total' => (int) round((float) $row->rushing_attempts),
                    'receptions_total' => (int) round((float) $row->receptions),
                    'receiving_yards_total' => (int) round($receivingYards),
                    'tackles_per_game' => round((float) $row->tackles_total / $games, 1),
                    'sacks_per_game' => round((float) $row->sacks / $games, 2),
                    'def_interceptions_per_game' => round((float) $row->defensive_interceptions / $games, 2),
                    'passes_defended_per_game' => round((float) $row->passes_defended / $games, 2),
                    'fumbles_recovered_per_game' => round((float) $row->fumbles_recovered / $games, 2),
                    'tackles_total' => (int) round((float) $row->tackles_total),
                    'sacks_total' => round((float) $row->sacks, 1),
                    'def_interceptions_total' => (int) round((float) $row->defensive_interceptions),
                    'passes_defended_total' => (int) round((float) $row->passes_defended),
                    'fumbles_recovered_total' => (int) round((float) $row->fumbles_recovered),
                    'field_goals_made_per_game' => round((float) $row->field_goals_made / $games, 2),
                    'extra_points_made_per_game' => round((float) $row->extra_points_made / $games, 2),
                    'field_goal_percentage_special' => round($fieldGoalPct, 1),
                    'extra_point_percentage' => round($extraPointPct, 1),
                    'field_goals_made_total' => (int) round((float) $row->field_goals_made),
                    'field_goals_attempted_total' => (int) round((float) $row->field_goals_attempted),
                    'extra_points_made_total' => (int) round((float) $row->extra_points_made),
                    'extra_points_attempted_total' => (int) round((float) $row->extra_points_attempted),
                ];
            })
            ->values();
    }
}
