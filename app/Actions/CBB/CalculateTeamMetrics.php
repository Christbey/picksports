<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballCalculateTeamMetrics;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;

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
        return 'CBB';
    }

    protected function sportKey(): string
    {
        return 'cbb';
    }

    protected function configPrefix(): string
    {
        return 'cbb';
    }

    protected function shouldGateByMinimumGames(): bool
    {
        return true;
    }
}
