<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'nfl:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate NFL team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['city', 'name'];
}
