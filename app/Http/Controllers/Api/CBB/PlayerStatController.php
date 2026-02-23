<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PlayerStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use Illuminate\Http\JsonResponse;

class PlayerStatController extends Controller
{
    /**
     * Display season averages for all players, for leaderboard/ranking purposes.
     */
    public function leaderboard(): JsonResponse
    {
        $stats = PlayerStat::query()
            ->selectRaw('
                player_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds_total) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(minutes_played) as minutes_per_game,
                SUM(field_goals_made) as total_fg_made,
                SUM(field_goals_attempted) as total_fg_attempted,
                SUM(three_point_made) as total_3p_made,
                SUM(three_point_attempted) as total_3p_attempted,
                SUM(free_throws_made) as total_ft_made,
                SUM(free_throws_attempted) as total_ft_attempted
            ')
            ->groupBy('player_id')
            ->havingRaw('COUNT(*) >= 10')
            ->get();

        $playerIds = $stats->pluck('player_id');
        $players = Player::query()
            ->with('team')
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        $data = $stats->map(function ($s) use ($players) {
            $player = $players->get($s->player_id);

            return [
                'player_id' => $s->player_id,
                'player' => $player ? [
                    'id' => $player->id,
                    'full_name' => $player->full_name,
                    'headshot_url' => $player->headshot_url,
                    'position' => $player->position,
                    'jersey_number' => $player->jersey_number,
                    'team' => $player->team ? [
                        'id' => $player->team->id,
                        'name' => $player->team->name,
                        'display_name' => $player->team->display_name,
                        'abbreviation' => $player->team->abbreviation,
                    ] : null,
                ] : null,
                'games_played' => (int) $s->games_played,
                'points_per_game' => round($s->points_per_game, 1),
                'rebounds_per_game' => round($s->rebounds_per_game, 1),
                'assists_per_game' => round($s->assists_per_game, 1),
                'steals_per_game' => round($s->steals_per_game, 1),
                'blocks_per_game' => round($s->blocks_per_game, 1),
                'minutes_per_game' => round((float) $s->minutes_per_game, 1),
                'field_goal_percentage' => $s->total_fg_attempted > 0
                    ? round(($s->total_fg_made / $s->total_fg_attempted) * 100, 1) : 0,
                'three_point_percentage' => $s->total_3p_attempted > 0
                    ? round(($s->total_3p_made / $s->total_3p_attempted) * 100, 1) : 0,
                'free_throw_percentage' => $s->total_ft_attempted > 0
                    ? round(($s->total_ft_made / $s->total_ft_attempted) * 100, 1) : 0,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Display a listing of CBB player stats.
     */
    public function index()
    {
        $stats = PlayerStat::query()
            ->with(['player', 'game'])
            ->orderByDesc('id')
            ->paginate(15);

        return PlayerStatResource::collection($stats);
    }

    /**
     * Display the specified CBB player stat.
     */
    public function show(PlayerStat $playerStat)
    {
        $playerStat->load(['player', 'game']);

        return new PlayerStatResource($playerStat);
    }

    /**
     * Display player stats for a specific game.
     */
    public function byGame(Game $game)
    {
        $stats = PlayerStat::query()
            ->with(['player', 'team'])
            ->where('game_id', $game->id)
            ->paginate(15);

        return PlayerStatResource::collection($stats);
    }

    /**
     * Display stats for a specific player.
     */
    public function byPlayer(Player $player)
    {
        $stats = PlayerStat::query()
            ->with(['game'])
            ->where('player_id', $player->id)
            ->orderByDesc('id')
            ->paginate(15);

        return PlayerStatResource::collection($stats);
    }
}
