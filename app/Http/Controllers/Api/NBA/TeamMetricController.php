<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\NBA\TeamMetricResource;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Services\TeamMetrics\TeamRecordService;
use Illuminate\Support\Collection;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    public function __construct(
        protected TeamRecordService $teamRecordService
    ) {}

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        $this->teamRecordService->applyRecords($metrics, 'nba_games');
    }
}
