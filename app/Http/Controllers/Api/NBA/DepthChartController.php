<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractDepthChartController;
use App\Models\NBA\DepthChartEntry;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;

class DepthChartController extends AbstractDepthChartController
{
    protected const SPORT_KEY = 'nba';

    protected const TEAM_MODEL = Team::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const DEPTH_CHART_ENTRY_MODEL = DepthChartEntry::class;
}
