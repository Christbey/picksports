<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\NBA\GameResource;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

class GameController extends AbstractGameController
{
    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const GAME_RESOURCE = GameResource::class;
}
