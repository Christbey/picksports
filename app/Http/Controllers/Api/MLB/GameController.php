<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\MLB\GameResource;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

class GameController extends AbstractGameController
{
    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const GAME_RESOURCE = GameResource::class;
}
