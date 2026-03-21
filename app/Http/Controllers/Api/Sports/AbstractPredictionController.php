<?php

namespace App\Http\Controllers\Api\Sports;

use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class AbstractPredictionController extends AbstractSportsApiController
{
    /**
     * @var array<string, bool>
     */
    protected static array $gameColumnPresence = [];

    protected const PREDICTION_MODEL = '';

    protected const GAME_MODEL = '';

    protected const PREDICTION_RESOURCE = '';

    protected function getPredictionModel(): string
    {
        if (static::PREDICTION_MODEL === '') {
            throw new \RuntimeException('PREDICTION_MODEL must be defined on prediction controller.');
        }

        return static::PREDICTION_MODEL;
    }

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on prediction controller.');
        }

        return static::GAME_MODEL;
    }

    protected function getPredictionResource(): string
    {
        if (static::PREDICTION_RESOURCE === '') {
            throw new \RuntimeException('PREDICTION_RESOURCE must be defined on prediction controller.');
        }

        return static::PREDICTION_RESOURCE;
    }

    /**
     * Apply sport-specific filters to the index query
     */
    protected function applyIndexFilters($query): void
    {
        $request = request();

        if ($request->filled('season') && $this->hasGameSeasonColumn()) {
            $query->whereHas('game', function ($q) {
                $q->where($this->getGameSeasonColumn(), request('season'));
            });
        }

        if ($request->filled('season_type') && $this->hasGameSeasonTypeColumn()) {
            $query->whereHas('game', function ($q) {
                $q->where($this->getGameSeasonTypeColumn(), request('season_type'));
            });
        }

        if ($request->filled('week') && $this->hasGameWeekColumn()) {
            $query->whereHas('game', function ($q) {
                $q->where($this->getGameWeekColumn(), request('week'));
            });
        }

        // Default: date range filtering
        if (request()->filled('from_date')) {
            $query->whereHas('game', function ($q) {
                $q->whereDate($this->getGameDateColumn(), '>=', request('from_date'));
            });
        }

        if (request()->filled('to_date')) {
            $query->whereHas('game', function ($q) {
                $q->whereDate($this->getGameDateColumn(), '<=', request('to_date'));
            });
        }
    }

    /**
     * Get the game date column name
     */
    protected function getGameDateColumn(): string
    {
        return 'game_date';
    }

    protected function getGameSeasonColumn(): string
    {
        return 'season';
    }

    protected function getGameSeasonTypeColumn(): string
    {
        return 'season_type';
    }

    protected function getGameWeekColumn(): string
    {
        return 'week';
    }

    protected function hasGameSeasonColumn(): bool
    {
        return $this->hasGameColumn($this->getGameSeasonColumn());
    }

    protected function hasGameSeasonTypeColumn(): bool
    {
        return $this->hasGameColumn($this->getGameSeasonTypeColumn());
    }

    protected function hasGameWeekColumn(): bool
    {
        return $this->hasGameColumn($this->getGameWeekColumn());
    }

    protected function hasGameColumn(string $column): bool
    {
        $gameInstance = new ($this->getGameModel());
        $cacheKey = $gameInstance->getTable().':'.$column;

        if (! array_key_exists($cacheKey, self::$gameColumnPresence)) {
            self::$gameColumnPresence[$cacheKey] = Schema::hasColumn($gameInstance->getTable(), $column);
        }

        return self::$gameColumnPresence[$cacheKey];
    }

    /**
     * Process predictions collection before applying tier limits
     */
    protected function processPredictions(Collection $predictions): Collection
    {
        return $predictions;
    }

    /**
     * Whether to return first prediction only in byGame method
     */
    protected function returnFirstPredictionOnly(): bool
    {
        return false;
    }

    /**
     * Display a listing of predictions
     */
    public function index(): AnonymousResourceCollection|JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();
        $tierContext = $this->resolveTierContext('getPredictionsLimit');
        $tierMetadata = $tierContext['metadata'];
        $tierLimit = $tierContext['limit'];
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
            segment: 'predictions_index',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.predictions_index_seconds', 60),
            resolver: function () use ($predictionModel, $resourceClass, $tierMetadata, $tierLimit): array {
                $query = $predictionModel::query()
                    ->with(['game.homeTeam', 'game.awayTeam']);

                $this->applyIndexFilters($query);

                $predictions = $query->latest()->get();

                // Allow sport-specific processing
                $predictions = $this->processPredictions($predictions);

                // Apply tier limit after processing
                if ($tierLimit !== null) {
                    $predictions = $predictions->take($tierLimit);
                }

                return $this->withTierMetadata($resourceClass::collection($predictions), $tierMetadata)
                    ->response()
                    ->getData(true);
            },
        );

        return response()->json($payload);
    }

    /**
     * Get available dates that have predictions
     */
    public function availableDates(): JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $gameInstance = new ($this->getGameModel());
        $predictionInstance = new $predictionModel;
        $request = request();

        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $request->query('season'),
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'predictions_available_dates',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.predictions_available_dates_seconds', 300),
            resolver: function () use ($predictionModel, $gameInstance, $predictionInstance, $request): array {
                $query = $predictionModel::query()
                    ->join(
                        $gameInstance->getTable(),
                        "{$gameInstance->getTable()}.id",
                        '=',
                        "{$predictionInstance->getTable()}.game_id"
                    )
                    ->select(DB::raw("DISTINCT DATE({$gameInstance->getTable()}.{$this->getGameDateColumn()}) as game_date"));

                if ($request->query('season') && $this->hasGameSeasonColumn()) {
                    $query->where("{$gameInstance->getTable()}.{$this->getGameSeasonColumn()}", $request->query('season'));
                }

                $dates = $query
                    ->orderBy('game_date')
                    ->pluck('game_date');

                return ['data' => $dates];
            },
        );

        return response()->json($payload);
    }

    public function availableSeasons(): JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $gameInstance = new ($this->getGameModel());
        $predictionInstance = new $predictionModel;
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash(['controller' => static::class]);

        $payload = $sportsViewCache->remember(
            segment: 'predictions_available_seasons',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.predictions_available_seasons_seconds', 600),
            resolver: function () use ($predictionModel, $gameInstance, $predictionInstance): array {
                if (! $this->hasGameSeasonColumn()) {
                    return ['data' => []];
                }

                $seasons = $predictionModel::query()
                    ->join(
                        $gameInstance->getTable(),
                        "{$gameInstance->getTable()}.id",
                        '=',
                        "{$predictionInstance->getTable()}.game_id"
                    )
                    ->whereNotNull("{$gameInstance->getTable()}.{$this->getGameSeasonColumn()}")
                    ->select(DB::raw("DISTINCT {$gameInstance->getTable()}.{$this->getGameSeasonColumn()} as season"))
                    ->orderByDesc('season')
                    ->pluck('season');

                return ['data' => $seasons];
            },
        );

        return response()->json($payload);
    }

    /**
     * Display the specified prediction
     */
    public function show($prediction): JsonResource
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();
        $predictionId = $this->requireNumericId($prediction);

        $prediction = $predictionModel::query()->with(['game'])->findOrFail($predictionId);

        return new $resourceClass($prediction);
    }

    /**
     * Display predictions for a specific game
     */
    public function byGame($game): JsonResource|AnonymousResourceCollection|JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();
        $gameId = $this->requireNumericId($game);
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'game_id' => $gameId,
            'first_only' => $this->returnFirstPredictionOnly(),
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'predictions_by_game',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.predictions_by_game_seconds', 30),
            resolver: function () use ($predictionModel, $resourceClass, $gameId): array {
                $query = $predictionModel::query()
                    ->where('game_id', $gameId)
                    ->orderByDesc('created_at');

                if ($this->returnFirstPredictionOnly()) {
                    $prediction = $query->first();

                    if (! $prediction) {
                        return ['data' => null, '__status' => 404];
                    }

                    return (new $resourceClass($prediction))->response()->getData(true);
                }

                $predictions = $query->paginate(15);

                return $resourceClass::collection($predictions)->response()->getData(true);
            },
        );

        $status = (int) ($payload['__status'] ?? 200);
        if (array_key_exists('__status', $payload)) {
            unset($payload['__status']);
        }

        return response()->json($payload, $status);
    }
}
