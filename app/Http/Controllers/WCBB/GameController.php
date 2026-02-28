<?php

namespace App\Http\Controllers\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\GameResource;
use App\Models\WCBB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderResourcePage('WCBB/Game', 'game', $game, GameResource::class, ['homeTeam', 'awayTeam']);
    }
}
