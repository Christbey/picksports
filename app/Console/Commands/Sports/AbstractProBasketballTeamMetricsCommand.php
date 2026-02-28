<?php

namespace App\Console\Commands\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractProBasketballTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
{
    protected function topTeamsTitle(): string
    {
        return 'Top 10 Teams by Net Rating:';
    }

    protected function topRatingColumn(): string
    {
        return 'net_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'Off Eff', 'Def Eff', 'Net Rtg', 'Tempo', 'SOS'];
    }

    protected function topTableFields(): array
    {
        return [
            'offensive_efficiency' => 1,
            'defensive_efficiency' => 1,
            'net_rating' => 1,
            'tempo' => 1,
            'strength_of_schedule' => 3,
        ];
    }

    protected function displayTeamMetric(Model $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Efficiency', round($metric->offensive_efficiency, 1)],
                ['Defensive Efficiency', round($metric->defensive_efficiency, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Tempo', round($metric->tempo, 1)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
