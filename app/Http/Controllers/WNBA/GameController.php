<?php

namespace App\Http\Controllers\WNBA;

use App\Http\Controllers\Controller;
use App\Models\WNBA\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderFormPage(
            'WNBA/Game',
            'gameId',
            $game->id,
            ['game' => $game->toArray()],
        );
    }
}
