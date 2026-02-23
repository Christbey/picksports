<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayerResource;
use App\Models\NBA\Player;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Player $player): Response
    {
        $player->load('team');

        return Inertia::render('NBA/Player', [
            'player' => (new PlayerResource($player))->resolve(),
        ]);
    }
}
