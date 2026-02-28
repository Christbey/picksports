<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractPlayController;
use App\Http\Resources\WCBB\PlayResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Play;

class PlayController extends AbstractPlayController
{
    protected const PLAY_MODEL = Play::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAY_RESOURCE = PlayResource::class;
}
