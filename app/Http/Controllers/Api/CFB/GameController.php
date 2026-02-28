<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\CFB\GameResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;

class GameController extends AbstractGameController
{
    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const GAME_RESOURCE = GameResource::class;
}
