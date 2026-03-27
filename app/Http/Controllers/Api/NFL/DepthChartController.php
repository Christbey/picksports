<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractDepthChartController;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;

class DepthChartController extends AbstractDepthChartController
{
    protected const SPORT_KEY = 'nfl';

    protected const TEAM_MODEL = Team::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const DEPTH_CHART_ENTRY_MODEL = DepthChartEntry::class;
}
