<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\NFL\GameResource;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

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
