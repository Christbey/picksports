<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractDepthChartController;
use App\Models\MLB\DepthChartEntry;
use App\Models\MLB\Game;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;

class DepthChartController extends AbstractDepthChartController
{
    protected const SPORT_KEY = 'mlb';

    protected const TEAM_MODEL = Team::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const DEPTH_CHART_ENTRY_MODEL = DepthChartEntry::class;
}
