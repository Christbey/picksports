<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\MLB\PredictionResource;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;

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
}
