<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractPlayController;
use App\Http\Resources\WNBA\PlayResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Play;

class PlayController extends AbstractPlayController
{
    protected const PLAY_MODEL = Play::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAY_RESOURCE = PlayResource::class;
}
