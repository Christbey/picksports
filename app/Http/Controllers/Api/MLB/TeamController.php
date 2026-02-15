<?php

namespace App\Http\Controllers\Api\MLB;

use App\Actions\MLB\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\MLB\TeamResource;
use App\Models\MLB\Team;

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
