<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;

class PredictionController extends Controller
{
    /**
     * Display a listing of NBA predictions.
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

        // Apply tier limit to total results
        if ($tierLimit !== null) {
            $query->limit($tierLimit);
        }

        $predictions = $query->latest()->get();

        return PredictionResource::collection($predictions)->additional([
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ]);
    }

    /**
     * Display the specified NBA prediction.
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
