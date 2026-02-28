<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\WCBB\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\WCBB\PlayerStat::class;
    protected const SKIP_DNP_OR_EMPTY_STATS = true;
}
