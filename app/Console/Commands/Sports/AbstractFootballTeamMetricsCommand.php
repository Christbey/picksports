<?php

namespace App\Console\Commands\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
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
        return ['Rank', 'Team', 'Off Rtg', 'Def Rtg', 'Net Rtg', 'Yards/G', 'TO Diff', 'SOS'];
    }

    protected function topTableFields(): array
    {
        return [
            'offensive_rating' => 1,
            'defensive_rating' => 1,
            'net_rating' => 1,
            'yards_per_game' => 1,
            'turnover_differential' => 1,
            'strength_of_schedule' => 3,
        ];
    }

    protected function displayTeamMetric(Model $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Rating', round($metric->offensive_rating, 1)],
                ['Defensive Rating', round($metric->defensive_rating, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Points Per Game', round($metric->points_per_game, 1)],
                ['Points Allowed Per Game', round($metric->points_allowed_per_game, 1)],
                ['Yards Per Game', round($metric->yards_per_game, 1)],
                ['Yards Allowed Per Game', round($metric->yards_allowed_per_game, 1)],
                ['Passing Yards Per Game', round($metric->passing_yards_per_game, 1)],
                ['Rushing Yards Per Game', round($metric->rushing_yards_per_game, 1)],
                ['Turnover Differential', round($metric->turnover_differential, 1)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
