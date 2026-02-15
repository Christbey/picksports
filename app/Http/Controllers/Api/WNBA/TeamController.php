<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\WNBA\TeamResource;
use App\Models\WNBA\Team;

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
        return 'display_name';
    }
}
