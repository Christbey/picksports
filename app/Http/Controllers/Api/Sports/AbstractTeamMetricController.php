<?php

namespace App\Http\Controllers\Api\Sports;

use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

abstract class AbstractTeamMetricController extends AbstractSportsApiController
{
    /**
     * @var array<string, bool>
     */
    protected static array $seasonColumnPresence = [];

    protected const TEAM_METRIC_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const TEAM_METRIC_RESOURCE = '';

    protected const INDEX_ORDER_BY_COLUMN = 'net_rating';

    protected const BY_TEAM_ORDER_BY_COLUMN = 'season';

    protected const BY_TEAM_RETURNS_LATEST_ONLY = false;

    protected const GAMES_TABLE = '';

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

    protected function gamesTable(): ?string
    {
        if (static::GAMES_TABLE !== '') {
            return static::GAMES_TABLE;
        }

        $teamModel = $this->getTeamModel();
        $namespace = (new \ReflectionClass($teamModel))->getNamespaceName();
        $gameModelClass = "{$namespace}\\Game";
        if (! class_exists($gameModelClass)) {
            return null;
        }

        return (new $gameModelClass())->getTable();
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
        $cacheKey = $instance->getTable().':'.$this->getSeasonColumn();

        if (! array_key_exists($cacheKey, self::$seasonColumnPresence)) {
            self::$seasonColumnPresence[$cacheKey] = Schema::hasColumn($instance->getTable(), $this->getSeasonColumn());
        }

        return self::$seasonColumnPresence[$cacheKey];
    }

    public function index(): AnonymousResourceCollection|JsonResponse
    {
        $model = $this->getTeamMetricModel();
        $resource = $this->getTeamMetricResource();
        $tierContext = $this->resolveTierContext('getTeamMetricsLimit');
        $tierMetadata = $tierContext['metadata'];
        $tierLimit = $tierContext['limit'];
        $metricsTable = (new $model())->getTable();
        $request = request();

        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'query' => $request->query(),
            'tier_limit' => $tierLimit,
            'tier_name' => $tierMetadata['tier_name'] ?? null,
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_metrics_index',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_metrics_index_seconds', 120),
            resolver: function () use ($model, $resource, $tierMetadata, $tierLimit, $metricsTable, $request): array {
                $query = $model::query()
                    ->with(['team'])
                    ->orderByDesc($this->getIndexOrderByColumn());

                if ($request->query('season') && $this->hasSeasonColumn()) {
                    $query->where($this->getSeasonColumn(), $request->query('season'));
                }

                if ($this->hasSeasonColumn() && $request->filled('season_type')) {
                    $gamesTable = $this->gamesTable();
                    $sportSlug = $this->sportSlugFromGamesTable($gamesTable);
                    $seasonTypeCandidates = $sportSlug
                        ? $this->resolveSeasonTypeCandidates($sportSlug, (string) $request->query('season_type'))
                        : [];

                    if ($gamesTable && $sportSlug && $seasonTypeCandidates !== []) {
                        $seasonColumn = $this->getSeasonColumn();
                        $finalStatus = (string) config("{$sportSlug}.statuses.final", 'STATUS_FINAL');

                        $query->whereExists(function ($gameQuery) use (
                            $gamesTable,
                            $metricsTable,
                            $seasonColumn,
                            $seasonTypeCandidates,
                            $finalStatus
                        ) {
                            $gameQuery->selectRaw('1')
                                ->from($gamesTable)
                                ->where(function ($teamMatchQuery) use ($gamesTable, $metricsTable) {
                                    $teamMatchQuery
                                        ->whereColumn("{$gamesTable}.home_team_id", "{$metricsTable}.team_id")
                                        ->orWhereColumn("{$gamesTable}.away_team_id", "{$metricsTable}.team_id");
                                })
                                ->whereColumn("{$gamesTable}.season", "{$metricsTable}.{$seasonColumn}")
                                ->where("{$gamesTable}.status", $finalStatus)
                                ->whereIn("{$gamesTable}.season_type", $seasonTypeCandidates);
                        });
                    }
                }

                if ($tierLimit !== null) {
                    $query->limit($tierLimit);
                }

                $metrics = $query->get();
                $this->mutateIndexMetrics($metrics);

                return $this->withTierMetadata($resource::collection($metrics), $tierMetadata)
                    ->response()
                    ->getData(true);
            },
        );

        return response()->json($payload);
    }

    private function sportSlugFromGamesTable(?string $gamesTable): ?string
    {
        if (! $gamesTable || ! str_ends_with($gamesTable, '_games')) {
            return null;
        }

        return (string) substr($gamesTable, 0, -strlen('_games'));
    }

    /**
     * @return array<int, int|string>
     */
    private function resolveSeasonTypeCandidates(string $sportSlug, string $requestedSeasonType): array
    {
        $requested = trim($requestedSeasonType);
        if ($requested === '') {
            return [];
        }

        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
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
            fn ($value) => $value !== null && $value !== ''
        )));
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

        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'team_id' => $teamId,
            'query' => $request->query(),
            'latest_only' => $this->byTeamReturnsLatestOnly(),
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_metrics_by_team',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_metrics_by_team_seconds', 120),
            resolver: function () use ($model, $resource, $teamId, $request): array {
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
                        return ['data' => null, '__status' => 404];
                    }

                    return (new $resource($metric))->response()->getData(true);
                }

                return $resource::collection($query->paginate($this->getPerPage($request)))
                    ->response()
                    ->getData(true);
            },
        );

        $status = (int) ($payload['__status'] ?? 200);
        if (array_key_exists('__status', $payload)) {
            unset($payload['__status']);
        }

        return response()->json($payload, $status);
    }

    public function availableSeasons(): JsonResponse
    {
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'season_column' => $this->getSeasonColumn(),
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_metrics_available_seasons',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_metrics_available_seasons_seconds', 600),
            resolver: function (): array {
                if (! $this->hasSeasonColumn()) {
                    return ['data' => []];
                }

                $model = $this->getTeamMetricModel();
                $seasons = $model::query()
                    ->whereNotNull($this->getSeasonColumn())
                    ->select($this->getSeasonColumn())
                    ->distinct()
                    ->orderByDesc($this->getSeasonColumn())
                    ->pluck($this->getSeasonColumn());

                return ['data' => $seasons];
            },
        );

        return response()->json($payload);
    }
}
