<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PlayResource;
use App\Models\CBB\Game;
use App\Models\CBB\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of CBB plays.
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
     * Display the specified CBB play.
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
