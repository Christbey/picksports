<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\TeamStatResource;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamStat;

class TeamStatController extends Controller
{
    /**
     * Display a listing of NBA team stats.
     */
    public function index()
    {
        $stats = TeamStat::query()
            ->with(['team', 'game'])
            ->orderByDesc('id')
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }

    /**
     * Display the specified NBA team stat.
     */
    public function show(TeamStat $teamStat)
    {
        $teamStat->load(['team', 'game']);

        return new TeamStatResource($teamStat);
    }

    /**
     * Display team stats for a specific game.
     */
    public function byGame(Game $game)
    {
        $stats = TeamStat::query()
            ->with(['team'])
            ->where('game_id', $game->id)
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }

    /**
     * Display stats for a specific team.
     */
    public function byTeam(Team $team)
    {
        $stats = TeamStat::query()
            ->with(['game'])
            ->where('team_id', $team->id)
            ->orderByDesc('id')
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }

    /**
     * Display season averages for all teams, for ranking purposes.
     */
    public function allSeasonAverages()
    {
        $stats = TeamStat::query()
            ->selectRaw('
                team_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(turnovers) as turnovers_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted
            ')
            ->groupBy('team_id')
            ->get();

        $data = $stats->map(function ($s) {
            return [
                'team_id' => $s->team_id,
                'points_per_game' => round($s->points_per_game, 1),
                'rebounds_per_game' => round($s->rebounds_per_game, 1),
                'assists_per_game' => round($s->assists_per_game, 1),
                'steals_per_game' => round($s->steals_per_game, 1),
                'blocks_per_game' => round($s->blocks_per_game, 1),
                'turnovers_per_game' => round($s->turnovers_per_game, 1),
                'field_goal_percentage' => $s->total_fg_attempted > 0
                    ? round(($s->total_fg_made / $s->total_fg_attempted) * 100, 1) : 0,
                'three_point_percentage' => $s->total_3p_attempted > 0
                    ? round(($s->total_3p_made / $s->total_3p_attempted) * 100, 1) : 0,
                'free_throw_percentage' => $s->total_ft_attempted > 0
                    ? round(($s->total_ft_made / $s->total_ft_attempted) * 100, 1) : 0,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Display season averages for a specific team.
     */
    public function seasonAverages(Team $team)
    {
        $stats = TeamStat::query()
            ->where('team_id', $team->id)
            ->selectRaw('
                team_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(turnovers) as turnovers_per_game,
                AVG(fouls) as fouls_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted,
                AVG(offensive_rebounds) as offensive_rebounds_per_game,
                AVG(defensive_rebounds) as defensive_rebounds_per_game,
                AVG(fast_break_points) as fast_break_points_per_game,
                AVG(points_in_paint) as points_in_paint_per_game,
                AVG(second_chance_points) as second_chance_points_per_game,
                AVG(bench_points) as bench_points_per_game
            ')
            ->groupBy('team_id')
            ->first();

        if (! $stats) {
            return response()->json(['data' => null], 404);
        }

        // Calculate percentages
        $fg_percentage = $stats->total_fg_attempted > 0
            ? round(($stats->total_fg_made / $stats->total_fg_attempted) * 100, 1)
            : 0;

        $three_p_percentage = $stats->total_3p_attempted > 0
            ? round(($stats->total_3p_made / $stats->total_3p_attempted) * 100, 1)
            : 0;

        $ft_percentage = $stats->total_ft_attempted > 0
            ? round(($stats->total_ft_made / $stats->total_ft_attempted) * 100, 1)
            : 0;

        return response()->json([
            'data' => [
                'team_id' => $stats->team_id,
                'games_played' => $stats->games_played,
                'points_per_game' => round($stats->points_per_game, 1),
                'rebounds_per_game' => round($stats->rebounds_per_game, 1),
                'assists_per_game' => round($stats->assists_per_game, 1),
                'steals_per_game' => round($stats->steals_per_game, 1),
                'blocks_per_game' => round($stats->blocks_per_game, 1),
                'turnovers_per_game' => round($stats->turnovers_per_game, 1),
                'fouls_per_game' => round($stats->fouls_per_game, 1),
                'field_goal_percentage' => $fg_percentage,
                'three_point_percentage' => $three_p_percentage,
                'free_throw_percentage' => $ft_percentage,
                'offensive_rebounds_per_game' => round($stats->offensive_rebounds_per_game, 1),
                'defensive_rebounds_per_game' => round($stats->defensive_rebounds_per_game, 1),
                'fast_break_points_per_game' => round($stats->fast_break_points_per_game, 1),
                'points_in_paint_per_game' => round($stats->points_in_paint_per_game, 1),
                'second_chance_points_per_game' => round($stats->second_chance_points_per_game, 1),
                'bench_points_per_game' => round($stats->bench_points_per_game, 1),
            ],
        ]);
    }
}
