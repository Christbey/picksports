<?php

namespace App\Http\Controllers\Api\CBB;

use App\Actions\CBB\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\CBB\TeamResource;
use App\Models\CBB\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const TRENDS_CALCULATOR = CalculateTeamTrends::class;
}
