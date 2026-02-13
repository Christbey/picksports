<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;

class PredictionController extends Controller
{
    /**
     * Display a listing of NFL predictions.
     */
    public function index()
    {
        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier?->getPredictionsLimit();

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam']);

        // Filter by season type if provided
        if (request('season_type')) {
            $query->whereHas('game', function ($q) {
                $q->where('season_type', request('season_type'));
            });
        }

        // Filter by week if provided
        if (request('week')) {
            $query->whereHas('game', function ($q) {
                $q->where('week', request('week'));
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
     * Display the specified NFL prediction.
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
        $predictions = Prediction::query()
            ->where('game_id', $game->id)
            ->with(['game.homeTeam', 'game.awayTeam', 'game.prediction'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return PredictionResource::collection($predictions);
    }
}
