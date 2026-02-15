<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\CFB\TeamResource;
use App\Models\CFB\Team;

class TeamController extends AbstractTeamController
{
    protected function getTeamModel(): string
    {
        return Team::class;
    }

    protected function getTeamResource(): string
    {
        return TeamResource::class;
    }

    protected function getOrderByColumn(): string
    {
        return 'school';
    }
}
