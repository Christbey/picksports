<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Models\CBB\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Game $game): Response
    {
        return Inertia::render('CBB/Game', [
            'game' => $game,
        ]);
    }
}
