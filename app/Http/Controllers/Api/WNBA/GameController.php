<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\WNBA\GameResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;

class GameController extends AbstractGameController
{
    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const GAME_RESOURCE = GameResource::class;
}
