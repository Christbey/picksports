<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\NBA\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\NBA\PlayerStat::class;
}
