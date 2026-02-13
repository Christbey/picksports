<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\PredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;

class PredictionController extends Controller
{
    /**
     * Display a listing of CFB predictions.
     */
    public function index()
    {
        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier?->getPredictionsLimit();

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam']);

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
     * Display the specified CFB prediction.
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
            ->orderByDesc('created_at')
            ->paginate(15);

        return PredictionResource::collection($predictions);
    }
}
