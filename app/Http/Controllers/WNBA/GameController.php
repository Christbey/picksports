<?php

namespace App\Http\Controllers\WNBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\WNBA\GameResource;
use App\Models\WNBA\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderResourcePage('WNBA/Game', 'game', $game, GameResource::class, ['homeTeam', 'awayTeam']);
    }
}
