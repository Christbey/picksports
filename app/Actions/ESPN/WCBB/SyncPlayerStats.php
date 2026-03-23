<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;
use App\Models\WCBB\Player;
use App\Models\WCBB\PlayerStat;
use App\Models\WCBB\Team;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    protected const SKIP_DNP_OR_EMPTY_STATS = true;
}
