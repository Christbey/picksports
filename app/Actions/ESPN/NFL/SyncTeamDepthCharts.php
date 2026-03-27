<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncTeamDepthCharts;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use App\Models\NFL\Team;

class SyncTeamDepthCharts extends AbstractSyncTeamDepthCharts
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const DEPTH_CHART_MODEL_CLASS = DepthChartEntry::class;
}
