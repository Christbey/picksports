<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\WNBA\TeamMetricResource;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;
}
