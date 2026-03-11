<?php

namespace App\Http\Controllers\MLB;

use App\Http\Controllers\Controller;
use App\Models\MLB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderIdPage('MLB/Game', 'gameId', $game->id);
    }
}
