<?php

namespace App\Http\Controllers\Api\NFL;

use App\Actions\NFL\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\NFL\TeamResource;
use App\Models\NFL\Team;

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
        return 'name';
    }
}
