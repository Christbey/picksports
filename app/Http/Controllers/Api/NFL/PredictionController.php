<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected function getPredictionModel(): string
    {
        return Prediction::class;
    }

    protected function getGameModel(): string
    {
        return Game::class;
    }

    protected function getPredictionResource(): string
    {
        return PredictionResource::class;
    }

    protected function applyIndexFilters($query): void
    {
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
    }
}
