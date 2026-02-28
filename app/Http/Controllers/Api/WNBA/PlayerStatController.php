<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\WNBA\PlayerStatResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Player;
use App\Models\WNBA\PlayerStat;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;
}
