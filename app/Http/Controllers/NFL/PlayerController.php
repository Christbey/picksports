<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Models\NFL\Player;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        return $this->renderIdPage('NFL/Player', 'playerId', $player->id);
    }
}
