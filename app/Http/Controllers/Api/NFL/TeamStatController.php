<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\NFL\TeamStatResource;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;
}
