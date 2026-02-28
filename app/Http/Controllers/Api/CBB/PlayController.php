<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractPlayController;
use App\Http\Resources\CBB\PlayResource;
use App\Models\CBB\Game;
use App\Models\CBB\Play;

class PlayController extends AbstractPlayController
{
    protected const PLAY_MODEL = Play::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAY_RESOURCE = PlayResource::class;
}
