<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractPlayerController;
use App\Http\Resources\WNBA\PlayerResource;
use App\Models\WNBA\Player;
use App\Models\WNBA\Team;

class PlayerController extends AbstractPlayerController
{
    protected const PLAYER_MODEL = Player::class;

    protected const TEAM_MODEL = Team::class;

    protected const PLAYER_RESOURCE = PlayerResource::class;

    protected const ORDER_BY_COLUMN = 'display_name';
}
