<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\MLB\TeamMetricResource;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const INDEX_ORDER_BY_COLUMN = 'offensive_rating';
}
