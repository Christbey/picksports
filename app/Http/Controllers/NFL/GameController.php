<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\GameResource;
use App\Models\NFL\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderResourcePage('NFL/Game', 'game', $game, GameResource::class, ['homeTeam', 'awayTeam', 'prediction']);
    }
}
