<?php

namespace App\Http\Controllers\Api\CBB;

use App\Actions\CBB\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\CBB\TeamResource;
use App\Models\CBB\Team;

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

    protected function getTrendsCalculator(): ?string
    {
        return CalculateTeamTrends::class;
    }

    protected function getOrderByColumn(): string
    {
        return 'display_name';
    }
}
