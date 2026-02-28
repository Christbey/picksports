<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\NFL\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'nfl:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate NFL team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\NFL\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['city', 'name'];
}
