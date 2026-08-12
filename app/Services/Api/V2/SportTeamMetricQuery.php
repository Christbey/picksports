<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SportTeamMetricQuery
{
    use BuildsSportQueries;

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 500;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): LengthAwarePaginator {
        return $this->query($context, $this->normalizeFilters($context, $filters), $user)
            ->paginate($this->perPage($filters, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function latestForTeam(
        SportContext $context,
        int|string $team,
        array $filters = [],
        ?Authenticatable $user = null,
        bool $includeTeam = true,
    ): Model {
        $metricModel = $this->metricModel($context);
        $table = (new $metricModel)->getTable();

        $filters = $this->normalizeFilters($context, $filters);

        return $metricModel::query()
            ->with($includeTeam ? $this->relationsFor($metricModel) : [])
            ->where('team_id', (int) $team)
            ->when(($filters['season'] ?? null) && $this->hasColumn($table, 'season'), fn (Builder $query): Builder => $query->where('season', $filters['season']))
            ->when($this->shouldFilterSeasonType($filters) && $this->hasColumn($table, 'season_type'), fn (Builder $query): Builder => $query->whereIn('season_type', $this->seasonTypeCandidates($context, (string) $filters['season_type'])))
            ->when($this->hasColumn($table, 'season'), fn (Builder $query): Builder => $query->orderByDesc('season'))
            ->when($this->hasColumn($table, 'calculation_date'), fn (Builder $query): Builder => $query->orderByDesc('calculation_date'))
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @return Collection<int, int>
     */
    public function availableSeasons(
        SportContext $context,
        ?Authenticatable $user = null,
    ): Collection {
        $metricModel = $this->metricModel($context);
        $table = (new $metricModel)->getTable();

        if (! $this->hasColumn($table, 'season')) {
            return collect();
        }

        return $metricModel::query()
            ->whereNotNull('season')
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->map(fn (mixed $season): int => (int) $season)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function query(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Builder {
        $metricModel = $this->metricModel($context);
        $table = (new $metricModel)->getTable();
        $filters = $this->normalizeFilters($context, $filters);

        return $metricModel::query()
            ->with($this->relationsFor($metricModel))
            ->when(($filters['team_id'] ?? null) && $this->hasColumn($table, 'team_id'), fn (Builder $query): Builder => $query->where('team_id', $filters['team_id']))
            ->when(($filters['season'] ?? null) && $this->hasColumn($table, 'season'), fn (Builder $query): Builder => $query->where('season', $filters['season']))
            ->when($this->shouldFilterSeasonType($filters) && $this->hasColumn($table, 'season_type'), fn (Builder $query): Builder => $query->whereIn('season_type', $this->seasonTypeCandidates($context, (string) $filters['season_type'])))
            ->orderByDesc($this->orderColumn($table));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(SportContext $context, array $filters): array
    {
        $seasonType = isset($filters['season_type']) ? trim((string) $filters['season_type']) : null;

        if ($seasonType === 'all') {
            $filters['season_type'] = 'all';

            return $filters;
        }

        if ($seasonType === null || $seasonType === '') {
            $defaultSeasonType = config("{$context->slug}.season.default_team_metrics_type");

            if ($defaultSeasonType !== null && $defaultSeasonType !== '') {
                $filters['season_type'] = (string) $defaultSeasonType;
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldFilterSeasonType(array $filters): bool
    {
        return isset($filters['season_type'])
            && trim((string) $filters['season_type']) !== ''
            && trim((string) $filters['season_type']) !== 'all';
    }

    /**
     * @return class-string<Model>
     */
    private function metricModel(SportContext $context): string
    {
        return $this->requireModel($context, 'team_metric', 'Team metrics');
    }

    /**
     * @param  class-string<Model>  $metricModel
     * @return array<int, string>
     */
    private function relationsFor(string $metricModel): array
    {
        return $this->availableRelations($metricModel, ['team']);
    }

    private function orderColumn(string $table): string
    {
        foreach (['net_rating', 'offensive_rating', 'offensive_efficiency', 'fpi', 'season', 'id'] as $column) {
            if ($this->hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }
}
