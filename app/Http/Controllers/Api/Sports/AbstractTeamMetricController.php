<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

abstract class AbstractTeamMetricController extends AbstractSportsApiController
{
    protected const TEAM_METRIC_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const TEAM_METRIC_RESOURCE = '';

    protected const INDEX_ORDER_BY_COLUMN = 'net_rating';

    protected const BY_TEAM_ORDER_BY_COLUMN = 'season';

    protected const BY_TEAM_RETURNS_LATEST_ONLY = false;

    protected function getTeamMetricModel(): string
    {
        if (static::TEAM_METRIC_MODEL === '') {
            throw new \RuntimeException('TEAM_METRIC_MODEL must be defined on team metric controller.');
        }

        return static::TEAM_METRIC_MODEL;
    }

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on team metric controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getTeamMetricResource(): string
    {
        if (static::TEAM_METRIC_RESOURCE === '') {
            throw new \RuntimeException('TEAM_METRIC_RESOURCE must be defined on team metric controller.');
        }

        return static::TEAM_METRIC_RESOURCE;
    }

    protected function getIndexOrderByColumn(): string
    {
        return static::INDEX_ORDER_BY_COLUMN;
    }

    protected function getByTeamOrderByColumn(): string
    {
        return static::BY_TEAM_ORDER_BY_COLUMN;
    }

    protected function byTeamReturnsLatestOnly(): bool
    {
        return static::BY_TEAM_RETURNS_LATEST_ONLY;
    }

    protected function mutateIndexMetrics(Collection $metrics): void
    {
        // Hook for sport-specific enrichment.
    }

    protected function getSeasonColumn(): string
    {
        return 'season';
    }

    protected function hasSeasonColumn(): bool
    {
        $model = $this->getTeamMetricModel();
        $instance = new $model();

        return Schema::hasColumn($instance->getTable(), $this->getSeasonColumn());
    }

    public function index(): AnonymousResourceCollection
    {
        $model = $this->getTeamMetricModel();
        $resource = $this->getTeamMetricResource();
        $tierContext = $this->resolveTierContext('getTeamMetricsLimit');
        $tierMetadata = $tierContext['metadata'];
        $tierLimit = $tierContext['limit'];

        $query = $model::query()
            ->with(['team'])
            ->orderByDesc($this->getIndexOrderByColumn());

        if (request('season') && $this->hasSeasonColumn()) {
            $query->where($this->getSeasonColumn(), request('season'));
        }

        if ($tierLimit !== null) {
            $query->limit($tierLimit);
        }

        $metrics = $query->get();
        $this->mutateIndexMetrics($metrics);

        return $this->withTierMetadata($resource::collection($metrics), $tierMetadata);
    }

    public function show($teamMetric): JsonResource
    {
        $model = $this->getTeamMetricModel();
        $resource = $this->getTeamMetricResource();
        $teamMetricId = $this->requireNumericId($teamMetric);

        $teamMetric = $model::query()
            ->with(['team'])
            ->findOrFail($teamMetricId);

        return new $resource($teamMetric);
    }

    public function byTeam($team, Request $request): AnonymousResourceCollection|JsonResource|JsonResponse
    {
        $teamModel = $this->getTeamModel();
        $model = $this->getTeamMetricModel();
        $resource = $this->getTeamMetricResource();
        $teamId = $this->requireNumericId($team);

        $teamModel::query()->findOrFail($teamId);

        $query = $model::query()
            ->with(['team'])
            ->where('team_id', $teamId)
            ->orderByDesc($this->getByTeamOrderByColumn());

        if ($request->filled('season') && $this->hasSeasonColumn()) {
            $query->where($this->getSeasonColumn(), $request->input('season'));
        }

        if ($this->byTeamReturnsLatestOnly()) {
            $metric = $query->first();

            if (! $metric) {
                return response()->json(['data' => null], 404);
            }

            return new $resource($metric);
        }

        return $resource::collection($query->paginate($this->getPerPage($request)));
    }

    public function availableSeasons(): JsonResponse
    {
        if (! $this->hasSeasonColumn()) {
            return response()->json(['data' => []]);
        }

        $model = $this->getTeamMetricModel();
        $seasons = $model::query()
            ->whereNotNull($this->getSeasonColumn())
            ->select($this->getSeasonColumn())
            ->distinct()
            ->orderByDesc($this->getSeasonColumn())
            ->pluck($this->getSeasonColumn());

        return response()->json(['data' => $seasons]);
    }
}
