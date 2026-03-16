<?php

namespace App\Http\Controllers\CFB;

use App\Http\Controllers\Controller;
use App\Models\CFB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderIdPage('CFB/Game', 'gameId', $game->id);
    }
}
