<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncTeamDepthCharts;
use App\Models\NBA\DepthChartEntry;
use App\Models\NBA\Player;
use App\Models\NBA\Team;

class SyncTeamDepthCharts extends AbstractSyncTeamDepthCharts
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const DEPTH_CHART_MODEL_CLASS = DepthChartEntry::class;
}
