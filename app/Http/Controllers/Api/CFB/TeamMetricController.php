<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractTeamMetricController;
use App\Http\Resources\CFB\TeamMetricResource;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Services\TeamMetrics\TeamRecordService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TeamMetricController extends AbstractTeamMetricController
{
    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_METRIC_RESOURCE = TeamMetricResource::class;

    protected const GAMES_TABLE = 'cfb_games';

    protected const BY_TEAM_ORDER_BY_COLUMN = 'season';

    public function __construct(
        protected TeamRecordService $teamRecordService
    ) {}

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        $this->teamRecordService->applyRecords($metrics, 'cfb_games');
    }

    protected function modifyIndexQuery(Builder $query): Builder
    {
        $affiliationsTable = (new TeamSeasonAffiliation)->getTable();
        $fbs = config('cfb.teams.divisions.fbs', 'FBS');

        return $query
            ->join($affiliationsTable, function ($join) use ($affiliationsTable, $fbs) {
                $join->on("{$affiliationsTable}.team_id", '=', 'cfb_team_metrics.team_id')
                    ->on("{$affiliationsTable}.season", '=', 'cfb_team_metrics.season')
                    ->where("{$affiliationsTable}.subdivision", '=', $fbs);
            })
            ->select('cfb_team_metrics.*')
            ->orderByDesc('cfb_team_metrics.cfp_rating')
            ->orderByDesc('cfb_team_metrics.net_rating');
    }
}
