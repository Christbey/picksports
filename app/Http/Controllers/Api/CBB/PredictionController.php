<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\PredictionResource;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;

class PredictionController extends Controller
{
    /**
     * Display a listing of CBB predictions.
     */
    public function index()
    {
        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier?->getPredictionsLimit();

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam']);

        // Filter by date range if provided
        if (request('from_date')) {
            $query->whereHas('game', function ($q) {
                $q->whereDate('game_date', '>=', request('from_date'));
            });
        }

        if (request('to_date')) {
            $query->whereHas('game', function ($q) {
                $q->whereDate('game_date', '<=', request('to_date'));
            });
        }

        // Get all predictions (we need to sort by betting value which is calculated on the fly)
        $allPredictions = $query->latest()->get();

        // Calculate betting value for each and sort by whether they have value
        $calculator = app(\App\Actions\CBB\CalculateBettingValue::class);

        $predictionsWithValue = $allPredictions->map(function ($prediction) use ($calculator) {
            $prediction->betting_value = $calculator->execute($prediction->game);
            $prediction->has_betting_value = ! empty($prediction->betting_value);
            $prediction->betting_value_count = $prediction->has_betting_value ? count($prediction->betting_value) : 0;

            return $prediction;
        });

        // Sort: betting value first, then by date
        $sorted = $predictionsWithValue->sortByDesc(function ($prediction) {
            // Games with betting value get priority (1000000 + count), others get timestamp
            return $prediction->has_betting_value
                ? 1000000 + $prediction->betting_value_count
                : $prediction->created_at->timestamp;
        })->values();

        // Apply tier limit to sorted results
        if ($tierLimit !== null) {
            $sorted = $sorted->take($tierLimit);
        }

        return PredictionResource::collection($sorted)->additional([
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ]);
    }

    /**
     * Display the specified CBB prediction.
     */
    public function show(Prediction $prediction)
    {
        $prediction->load(['game']);

        return new PredictionResource($prediction);
    }

    /**
     * Display predictions for a specific game.
     */
    public function byGame(Game $game)
    {
        $prediction = Prediction::query()
            ->where('game_id', $game->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $prediction) {
            return response()->json(['data' => null], 404);
        }

        return new PredictionResource($prediction);
    }
}
