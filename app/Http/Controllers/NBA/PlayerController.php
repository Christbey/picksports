<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Models\NBA\Player;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __invoke(Player $player): Response
    {
        return $this->renderIdPage('NBA/Player', 'playerId', $player->id);
    }
}
