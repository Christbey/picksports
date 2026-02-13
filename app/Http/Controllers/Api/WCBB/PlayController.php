<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\PlayResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of WCBB plays.
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
     * Display the specified WCBB play.
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
