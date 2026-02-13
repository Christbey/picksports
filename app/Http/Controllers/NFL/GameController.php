<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\GameResource;
use App\Models\NFL\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        $game->load(['homeTeam', 'awayTeam', 'prediction']);

        return Inertia::render('NFL/Game', [
            'game' => GameResource::make($game)->resolve(),
        ]);
    }
}
