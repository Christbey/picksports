<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Models\CBB\Player;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        return $this->renderIdPage('CBB/Player', 'playerId', $player->id);
    }
}
