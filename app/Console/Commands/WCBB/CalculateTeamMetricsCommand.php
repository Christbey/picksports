<?php

namespace App\Console\Commands\WCBB;

use App\Console\Commands\Sports\AbstractAdjustedBasketballTeamMetricsCommand;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractAdjustedBasketballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'wcbb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate WCBB team advanced metrics (offensive/defensive efficiency, tempo, net rating)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\WCBB\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const METRICS_CONFIG_PREFIX = 'wcbb';
}
