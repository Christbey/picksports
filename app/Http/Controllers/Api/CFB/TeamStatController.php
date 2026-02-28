<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\CFB\TeamStatResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;
}
