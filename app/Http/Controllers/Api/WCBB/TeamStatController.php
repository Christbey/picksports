<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\WCBB\TeamStatResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;
}
