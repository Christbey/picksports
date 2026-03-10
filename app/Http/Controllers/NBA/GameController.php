<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Models\NBA\Game;
use Inertia\Response;

class GameController extends Controller
{
    public function __invoke(Game $game): Response
    {
        return $this->renderFormPage(
            'NBA/Game',
            'gameId',
            $game->id,
            ['game' => $game->toArray()],
        );
    }
}
