<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\MLB\GameResource;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

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
