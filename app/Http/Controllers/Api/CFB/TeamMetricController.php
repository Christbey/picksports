<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\CFB\TeamMetricResource;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Services\TeamMetrics\TeamRecordService;
use Illuminate\Support\Collection;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const GAMES_TABLE = 'cfb_games';

    protected const BY_TEAM_ORDER_BY_COLUMN = 'year';

    public function __construct(
        protected TeamRecordService $teamRecordService
    ) {}

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        $this->teamRecordService->applyRecords($metrics, 'cfb_games');
    }
}
