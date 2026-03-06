<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballCalculateTeamMetrics;
use App\Models\WCBB\Game;
use App\Models\WCBB\Play;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;

class CalculateTeamMetrics extends AbstractCollegeBasketballCalculateTeamMetrics
{
    protected function teamModelClass(): string
    {
        return Team::class;
    }

    protected function teamMetricModelClass(): string
    {
        return TeamMetric::class;
    }

    protected function gameModelClass(): string
    {
        return Game::class;
    }

    protected function playModelClass(): string
    {
        return Play::class;
    }

    protected function sportCode(): string
    {
        return 'WCBB';
    }

    protected function sportKey(): string
    {
        return 'wcbb';
    }

    protected function configPrefix(): string
    {
        return 'wcbb';
    }

    protected function shouldGateByMinimumGames(): bool
    {
        return false;
    }
}
