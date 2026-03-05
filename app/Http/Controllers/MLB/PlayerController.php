<?php

namespace App\Http\Controllers\MLB;

use App\Http\Controllers\Controller;
use App\Http\Resources\MLB\PlayerResource;
use App\Models\MLB\Player;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        $player->load(['team', 'activeInjuries.player']);

        return Inertia::render('MLB/Player', [
            'player' => (new PlayerResource($player))->resolve(),
        ]);
    }
}

