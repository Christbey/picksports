<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\PlayResource;
use App\Models\CFB\Game;
use App\Models\CFB\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of CFB plays.
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
     * Display the specified CFB play.
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
