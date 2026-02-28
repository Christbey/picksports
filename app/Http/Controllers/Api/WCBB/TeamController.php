<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Actions\WCBB\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\WCBB\TeamResource;
use App\Models\WCBB\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const TRENDS_CALCULATOR = CalculateTeamTrends::class;
}
