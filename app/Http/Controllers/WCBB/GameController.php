<?php

namespace App\Http\Controllers\WCBB;

use App\Http\Controllers\Controller;
use App\Models\WCBB\Game;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Game $game): Response
    {
        return Inertia::render('WCBB/Game', [
            'game' => $game,
        ]);
    }
}
