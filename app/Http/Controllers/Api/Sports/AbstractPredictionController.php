<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

abstract class AbstractPredictionController extends AbstractSportsApiController
{
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
        // Default: date range filtering
        if (request('from_date')) {
            $query->whereHas('game', function ($q) {
                $q->whereDate($this->getGameDateColumn(), '>=', request('from_date'));
            });
        }

        if (request('to_date')) {
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
    public function index(): AnonymousResourceCollection
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();
        $tierContext = $this->resolveTierContext('getPredictionsLimit');
        $tierMetadata = $tierContext['metadata'];
        $tierLimit = $tierContext['limit'];

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

        return $this->withTierMetadata($resourceClass::collection($predictions), $tierMetadata);
    }

    /**
     * Get available dates that have predictions
     */
    public function availableDates(): JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $gameInstance = new ($this->getGameModel());
        $predictionInstance = new $predictionModel();

        $dates = $predictionModel::query()
            ->join(
                $gameInstance->getTable(),
                "{$gameInstance->getTable()}.id",
                '=',
                "{$predictionInstance->getTable()}.game_id"
            )
            ->select(DB::raw("DISTINCT DATE({$gameInstance->getTable()}.{$this->getGameDateColumn()}) as game_date"))
            ->orderBy('game_date')
            ->pluck('game_date');

        return response()->json(['data' => $dates]);
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

        $query = $predictionModel::query()
            ->where('game_id', $gameId)
            ->orderByDesc('created_at');

        if ($this->returnFirstPredictionOnly()) {
            $prediction = $query->first();

            if (! $prediction) {
                return response()->json(['data' => null], 404);
            }

            return new $resourceClass($prediction);
        }

        $predictions = $query->paginate(15);

        return $resourceClass::collection($predictions);
    }
}
