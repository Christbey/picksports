<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\WNBA\TeamResource;
use App\Models\WNBA\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const ORDER_BY_COLUMN = 'location';
}
