<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\Team;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    protected const SKIP_DNP_OR_EMPTY_STATS = true;
}
