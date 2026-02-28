<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\CFB\TeamResource;
use App\Models\CFB\Team;

class TeamController extends AbstractTeamController
{
    protected const TEAM_MODEL = Team::class;

    protected const TEAM_RESOURCE = TeamResource::class;

    protected const ORDER_BY_COLUMN = 'school';
}
