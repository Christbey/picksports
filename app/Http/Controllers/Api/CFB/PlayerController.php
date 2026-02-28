<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractPlayerController;
use App\Http\Resources\CFB\PlayerResource;
use App\Models\CFB\Player;
use App\Models\CFB\Team;

class PlayerController extends AbstractPlayerController
{
    protected const PLAYER_MODEL = Player::class;

    protected const TEAM_MODEL = Team::class;

    protected const PLAYER_RESOURCE = PlayerResource::class;
}
