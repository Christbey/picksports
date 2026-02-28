<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractProBasketballTeamMetricsCommand;
use App\Models\NBA\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractProBasketballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'nba:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate NBA team advanced metrics (offensive/defensive efficiency, tempo, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\NBA\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['school', 'mascot'];
}
