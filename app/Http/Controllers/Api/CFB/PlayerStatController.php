<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\PlayerStatResource;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;

class PlayerStatController extends Controller
{
    /**
     * Display a listing of CFB player stats.
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
     * Display the specified CFB player stat.
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
            ->with(['player'])
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
