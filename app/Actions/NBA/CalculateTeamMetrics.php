<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractProfessionalBasketballCalculateTeamMetrics;
use App\Models\NBA\Play;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

class CalculateTeamMetrics extends AbstractProfessionalBasketballCalculateTeamMetrics
{
    protected function teamModelClass(): string
    {
        return Team::class;
    }

    protected function teamMetricModelClass(): string
    {
        return TeamMetric::class;
    }

    protected function playModelClass(): string
    {
        return Play::class;
    }

    protected function sportCode(): string
    {
        return 'NBA';
    }

    protected function sportKey(): string
    {
        return 'nba';
    }

    protected function configPrefix(): string
    {
        return 'nba';
    }

    protected function includesTrueEpaMetrics(): bool
    {
        return true;
    }

    protected function shouldLogNoGames(): bool
    {
        return true;
    }

    protected function shouldLogCalculatedMetrics(): bool
    {
        return true;
    }

    protected function shouldValidateMetrics(): bool
    {
        return true;
    }
}
