<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\WCBB\TeamMetricResource;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const BY_TEAM_ORDER_BY_COLUMN = 'year';
}
