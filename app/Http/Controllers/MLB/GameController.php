<?php

namespace App\Http\Controllers\MLB;

use App\Http\Controllers\Controller;
use App\Models\MLB\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Game $game): Response
    {
        return Inertia::render('MLB/Game', [
            'game' => $game,
        ]);
    }
}
