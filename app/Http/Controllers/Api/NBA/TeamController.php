<?php

namespace App\Http\Controllers\Api\NBA;

use App\Actions\NBA\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\NBA\TeamResource;
use App\Models\NBA\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const TRENDS_CALCULATOR = CalculateTeamTrends::class;
}
