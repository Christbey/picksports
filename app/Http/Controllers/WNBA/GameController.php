<?php

namespace App\Http\Controllers\WNBA;

use App\Http\Controllers\Controller;
use App\Models\WNBA\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Game $game): Response
    {
        return Inertia::render('WNBA/Game', [
            'game' => $game,
        ]);
    }
}
