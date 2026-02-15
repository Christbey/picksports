<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\CFB\PredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;

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
        // CFB doesn't have custom filters
    }
}
