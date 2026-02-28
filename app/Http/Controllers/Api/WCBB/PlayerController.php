<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractPlayerController;
use App\Http\Resources\WCBB\PlayerResource;
use App\Models\WCBB\Player;
use App\Models\WCBB\Team;

class PlayerController extends AbstractPlayerController
{
    protected const PLAYER_MODEL = Player::class;

    protected const TEAM_MODEL = Team::class;

    protected const PLAYER_RESOURCE = PlayerResource::class;

    protected const ORDER_BY_COLUMN = 'display_name';
}
