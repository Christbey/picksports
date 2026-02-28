<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const TEAM_STAT_MODEL_CLASS = \App\Models\WCBB\TeamStat::class;
}
