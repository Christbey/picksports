<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Models\NFL\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderFormPage(
            'NFL/Game',
            'gameId',
            $game->id,
            ['game' => $game->toArray()],
        );
    }
}
