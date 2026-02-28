<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractCalculateTeamMetricsCommand;
use App\Models\MLB\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
{
    protected const COMMAND_NAME = 'mlb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate MLB team metrics (offensive, pitching, defensive ratings, ERA, batting average)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\MLB\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\MLB\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['location', 'name'];

    protected function topTeamsTitle(): string
    {
        return 'Top 10 Teams by Offensive Rating:';
    }

    protected function topRatingColumn(): string
    {
        return 'offensive_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'Off Rtg', 'Pitch Rtg', 'Def Rtg', 'R/G', 'RA/G', 'AVG', 'ERA', 'SOS'];
    }

    protected function topTableFields(): array
    {
        return [
            'offensive_rating' => 2,
            'pitching_rating' => 2,
            'defensive_rating' => 2,
            'runs_per_game' => 2,
            'runs_allowed_per_game' => 2,
            'batting_average' => 3,
            'team_era' => 2,
            'strength_of_schedule' => 3,
        ];
    }

    protected function displayTeamMetric(TeamMetric $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Offensive Rating', round($metric->offensive_rating, 2)],
                ['Pitching Rating', round($metric->pitching_rating, 2)],
                ['Defensive Rating', round($metric->defensive_rating, 2)],
                ['Runs Per Game', round($metric->runs_per_game, 2)],
                ['Runs Allowed Per Game', round($metric->runs_allowed_per_game, 2)],
                ['Batting Average', round($metric->batting_average, 3)],
                ['Team ERA', round($metric->team_era, 2)],
                ['Strength of Schedule', $metric->strength_of_schedule ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
