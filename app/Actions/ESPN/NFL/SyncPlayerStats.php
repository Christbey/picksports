<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    protected const EXTENDED_CATEGORIES = true;
}
