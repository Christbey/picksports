<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Models\CBB\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderFormPage(
            'CBB/Game',
            'gameId',
            $game->id,
            ['game' => $game->toArray()],
        );
    }
}
