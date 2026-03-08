<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\NFL\TeamMetricResource;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Services\TeamMetrics\TeamRecordService;
use Illuminate\Support\Collection;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const GAMES_TABLE = 'nfl_games';

    public function __construct(
        protected TeamRecordService $teamRecordService
    ) {}

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        $this->teamRecordService->applyRecords($metrics, 'nfl_games');
    }
}
