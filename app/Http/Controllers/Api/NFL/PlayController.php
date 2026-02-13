<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\PlayResource;
use App\Models\NFL\Game;
use App\Models\NFL\Play;

class PlayController extends Controller
{
    /**
     * Display a listing of NFL plays.
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
     * Display the specified NFL play.
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
