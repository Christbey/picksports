<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\CFB\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'cfb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate CFB team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\CFB\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['display_name'];
}
