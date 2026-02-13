<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayResource;
use App\Models\NBA\Game;
use App\Models\NBA\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of NBA plays.
     */
    public function index()
    {
        $plays = Play::query()
            ->with(['game'])
            ->orderByDesc('id')
            ->paginate(15);

        return PlayResource::collection($plays);
    }

    /**
     * Display the specified NBA play.
     */
    public function show(Play $play)
    {
        $play->load(['game']);

        return new PlayResource($play);
    }

    /**
     * Display plays for a specific game.
     */
    public function byGame(Game $game)
    {
        $plays = Play::query()
            ->where('game_id', $game->id)
            ->orderBy('id')
            ->paginate(15);

        return PlayResource::collection($plays);
    }
}
