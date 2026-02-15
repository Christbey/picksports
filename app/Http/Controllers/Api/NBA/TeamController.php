<?php

namespace App\Http\Controllers\Api\NBA;

use App\Actions\NBA\CalculateTeamTrends;
use App\Http\Controllers\Api\Sports\AbstractTeamController;
use App\Http\Resources\NBA\TeamResource;
use App\Models\NBA\Team;

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
