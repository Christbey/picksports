<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;

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

    protected function returnFirstPredictionOnly(): bool
    {
        return true;
    }
}
