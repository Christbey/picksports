<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PlayerStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;

class PlayerStatController extends Controller
{
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
