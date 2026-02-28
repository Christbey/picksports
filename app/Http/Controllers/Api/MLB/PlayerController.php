<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPlayerController;
use App\Http\Resources\MLB\PlayerResource;
use App\Models\MLB\Player;
use App\Models\MLB\Team;

class PlayerController extends AbstractPlayerController
{
    protected const PLAYER_MODEL = Player::class;

    protected const TEAM_MODEL = Team::class;

    protected const PLAYER_RESOURCE = PlayerResource::class;

    protected const BY_TEAM_PAGINATED = false;
}
