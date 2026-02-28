<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractPlayerController;
use App\Http\Resources\NBA\PlayerResource;
use App\Models\NBA\Player;
use App\Models\NBA\Team;

class PlayerController extends AbstractPlayerController
{
    protected const PLAYER_MODEL = Player::class;

    protected const TEAM_MODEL = Team::class;

    protected const PLAYER_RESOURCE = PlayerResource::class;

    protected const BY_TEAM_PAGINATED = false;
}
