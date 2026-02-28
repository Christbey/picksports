<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractAdjustedBasketballTeamMetricsCommand;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractAdjustedBasketballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'cbb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate CBB team advanced metrics (offensive/defensive efficiency, tempo, net rating)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\CBB\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const METRICS_CONFIG_PREFIX = 'cbb';
}
