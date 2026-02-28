<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\AbstractProBasketballTeamMetricsCommand;
use App\Models\WNBA\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractProBasketballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'wnba:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate WNBA team advanced metrics (offensive/defensive efficiency, tempo, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\WNBA\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['display_name'];
}
