<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractPredictionController extends Controller
{
    /**
     * Get the Prediction model class for this sport
     */
    abstract protected function getPredictionModel(): string;

    /**
     * Get the Game model class for this sport
     */
    abstract protected function getGameModel(): string;

    /**
     * Get the PredictionResource class for this sport
     */
    abstract protected function getPredictionResource(): string;

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

        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier?->getPredictionsLimit();

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

        return $resourceClass::collection($predictions)->additional([
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ]);
    }

    /**
     * Display the specified prediction
     */
    public function show(int $prediction): JsonResource
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();

        $prediction = $predictionModel::query()->with(['game'])->findOrFail($prediction);

        return new $resourceClass($prediction);
    }

    /**
     * Display predictions for a specific game
     */
    public function byGame(int $game): JsonResource|AnonymousResourceCollection|JsonResponse
    {
        $predictionModel = $this->getPredictionModel();
        $resourceClass = $this->getPredictionResource();

        $query = $predictionModel::query()
            ->where('game_id', $game)
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
