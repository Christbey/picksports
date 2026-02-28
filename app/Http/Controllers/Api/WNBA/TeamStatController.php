<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\WNBA\TeamStatResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamStat;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;
}
