<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\CBB\GameResource;
use App\Models\CBB\Game;
use App\Models\CBB\Team;

class GameController extends AbstractGameController
{
    protected function getGameModel(): string
    {
        return Game::class;
    }

    protected function getTeamModel(): string
    {
        return Team::class;
    }

    protected function getGameResource(): string
    {
        return GameResource::class;
    }
}
