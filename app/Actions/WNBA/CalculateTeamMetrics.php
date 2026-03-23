<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractProfessionalBasketballCalculateTeamMetrics;
use App\Models\WNBA\Play;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;

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
        // WNBA team metrics do not currently persist true-EPA fields.
        return Play::class;
    }

    protected function sportCode(): string
    {
        return 'WNBA';
    }

    protected function sportKey(): string
    {
        return 'wnba';
    }

    protected function configPrefix(): string
    {
        return 'wnba';
    }

    protected function includesTrueEpaMetrics(): bool
    {
        return false;
    }
}
