<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayerStatResource;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;

class PlayerStatController extends Controller
{
    /**
     * Display a listing of NBA player stats.
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
     * Display the specified NBA player stat.
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
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->where('player_id', $player->id)
            ->orderByDesc('id')
            ->paginate(100);

        return PlayerStatResource::collection($stats);
    }
}
