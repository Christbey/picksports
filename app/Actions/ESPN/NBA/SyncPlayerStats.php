<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;
}
