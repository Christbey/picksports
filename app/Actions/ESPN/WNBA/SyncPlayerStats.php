<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;
use App\Models\WNBA\Player;
use App\Models\WNBA\PlayerStat;
use App\Models\WNBA\Team;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;
}
