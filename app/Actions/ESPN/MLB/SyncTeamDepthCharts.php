<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncTeamDepthCharts;
use App\Models\MLB\DepthChartEntry;
use App\Models\MLB\Player;
use App\Models\MLB\Team;

class SyncTeamDepthCharts extends AbstractSyncTeamDepthCharts
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const DEPTH_CHART_MODEL_CLASS = DepthChartEntry::class;
}
