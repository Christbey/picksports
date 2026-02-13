<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\WNBA\PlayResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of WNBA plays.
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
     * Display the specified WNBA play.
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
