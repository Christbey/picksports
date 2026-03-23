<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractProBasketballTeamMetricsCommand;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

class CalculateTeamMetricsCommand extends AbstractProBasketballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'nba:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate NBA team advanced metrics (offensive/defensive efficiency, tempo, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['city', 'name'];
}
