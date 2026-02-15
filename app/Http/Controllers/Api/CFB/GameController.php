<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\CFB\GameResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;

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
