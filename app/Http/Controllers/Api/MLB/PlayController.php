<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPlayController;
use App\Http\Resources\MLB\PlayResource;
use App\Models\MLB\Game;
use App\Models\MLB\Play;

class PlayController extends AbstractPlayController
{
    protected const PLAY_MODEL = Play::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAY_RESOURCE = PlayResource::class;
}
