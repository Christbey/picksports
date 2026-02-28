<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\CFB\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\CFB\PlayerStat::class;
}
