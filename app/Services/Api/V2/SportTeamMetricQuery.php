<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SportTeamMetricQuery
{
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
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function latestForTeam(
        SportContext $context,
        int|string $team,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Model {
        $metricModel = $this->metricModel($context);
        $table = (new $metricModel)->getTable();

        $filters = $this->normalizeFilters($context, $filters);

        return $metricModel::query()
            ->with($this->relationsFor($metricModel))
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
        $metricModel = $context->models['team_metric'] ?? null;

        if (! is_string($metricModel) || ! is_subclass_of($metricModel, Model::class)) {
            abort(404, "Team metrics are not available for {$context->slug}.");
        }

        return $metricModel;
    }

    /**
     * @param  class-string<Model>  $metricModel
     * @return array<int, string>
     */
    private function relationsFor(string $metricModel): array
    {
        return method_exists($metricModel, 'team') ? ['team'] : [];
    }

    /**
     * @return array<int, int|string>
     */
    private function seasonTypeCandidates(SportContext $context, string $requestedSeasonType): array
    {
        $requested = trim($requestedSeasonType);

        if ($requested === '') {
            return [];
        }

        $typeNames = config("{$context->slug}.season.type_names", []);
        $typesByKey = config("{$context->slug}.season.types", []);
        $candidates = [$requested];

        if (is_numeric($requested)) {
            $code = (int) $requested;
            $candidates[] = $code;
            $matchedKey = array_search($code, $typesByKey, true);

            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = (string) $typeNames[$matchedKey];
                }
            }
        } else {
            if (isset($typesByKey[$requested])) {
                $resolvedCode = $typesByKey[$requested];
                $candidates[] = $resolvedCode;
                $candidates[] = (string) $resolvedCode;
            }

            $matchedKey = array_search($requested, $typeNames, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typesByKey[$matchedKey])) {
                    $resolvedCode = $typesByKey[$matchedKey];
                    $candidates[] = $resolvedCode;
                    $candidates[] = (string) $resolvedCode;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn (mixed $value): bool => $value !== null && $value !== ''
        )));
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

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
