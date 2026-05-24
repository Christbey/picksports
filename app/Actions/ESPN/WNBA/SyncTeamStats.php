<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamStat;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;
}
