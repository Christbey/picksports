<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Models\NBA\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Game $game): Response
    {
        return Inertia::render('NBA/Game', [
            'game' => $game,
        ]);
    }
}
