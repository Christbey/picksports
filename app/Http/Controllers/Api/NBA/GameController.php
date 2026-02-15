<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\NBA\GameResource;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

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
