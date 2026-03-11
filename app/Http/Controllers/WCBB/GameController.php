<?php

namespace App\Http\Controllers\WCBB;

use App\Http\Controllers\Controller;
use App\Models\WCBB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderIdPage('WCBB/Game', 'gameId', $game->id);
    }
}
