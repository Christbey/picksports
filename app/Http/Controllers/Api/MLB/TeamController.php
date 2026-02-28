<?php

namespace App\Http\Controllers\Api\MLB;

use App\Actions\MLB\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\MLB\TeamResource;
use App\Models\MLB\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const TRENDS_CALCULATOR = CalculateTeamTrends::class;

    protected const ORDER_BY_COLUMN = 'name';
}
