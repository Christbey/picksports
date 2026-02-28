<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPlayController;
use App\Http\Resources\NFL\PlayResource;
use App\Models\NFL\Game;
use App\Models\NFL\Play;

class PlayController extends AbstractPlayController
{
    protected const PLAY_MODEL = Play::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAY_RESOURCE = PlayResource::class;
}
