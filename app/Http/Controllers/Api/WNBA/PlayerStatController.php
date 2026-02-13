<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\WNBA\PlayerStatResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Player;
use App\Models\WNBA\PlayerStat;

class PlayerStatController extends Controller
{
    /**
     * Display a listing of WNBA player stats.
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
     * Display the specified WNBA player stat.
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
