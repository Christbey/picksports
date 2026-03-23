<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\NBA\Team;
use App\Models\NBA\TeamStat;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;
}
