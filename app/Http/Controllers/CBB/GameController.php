<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\GameResource;
use App\Models\CBB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderResourcePage('CBB/Game', 'game', $game, GameResource::class, ['homeTeam', 'awayTeam']);
    }
}
