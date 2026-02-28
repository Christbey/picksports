<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const TEAM_STAT_MODEL_CLASS = \App\Models\NBA\TeamStat::class;
}
