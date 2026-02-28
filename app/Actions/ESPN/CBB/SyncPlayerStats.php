<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\CBB\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\CBB\PlayerStat::class;
    protected const SKIP_DNP_OR_EMPTY_STATS = true;
}
