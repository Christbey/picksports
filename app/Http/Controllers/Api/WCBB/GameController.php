<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractGameController;
use App\Http\Resources\WCBB\GameResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;

class GameController extends AbstractGameController
{
    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const GAME_RESOURCE = GameResource::class;
}
