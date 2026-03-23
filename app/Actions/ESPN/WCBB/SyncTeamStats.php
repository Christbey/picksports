<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;
}
