<?php

namespace App\Http\Controllers\CFB;

use App\Http\Controllers\Controller;
use App\Models\CFB\Player;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        return $this->renderIdPage('CFB/Player', 'playerId', $player->id);
    }
}
