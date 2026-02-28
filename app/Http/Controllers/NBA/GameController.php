<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\GameResource;
use App\Models\NBA\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderResourcePage('NBA/Game', 'game', $game, GameResource::class, ['homeTeam', 'awayTeam', 'prediction']);
    }
}
