<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\WNBA\PredictionResource;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;

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
        // WNBA doesn't have custom filters
    }
}
