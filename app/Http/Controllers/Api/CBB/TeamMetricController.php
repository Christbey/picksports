<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\CBB\TeamMetricResource;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Services\TeamMetrics\TeamRecordService;
use Illuminate\Support\Collection;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const BY_TEAM_RETURNS_LATEST_ONLY = true;

    public function __construct(
        protected TeamRecordService $teamRecordService
    ) {}

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        $this->teamRecordService->applyRecords($metrics, 'cbb_games');
    }
}
