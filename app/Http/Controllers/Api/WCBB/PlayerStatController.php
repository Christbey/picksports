<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\WCBB\PlayerStatResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Player;
use App\Models\WCBB\PlayerStat;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;
}
