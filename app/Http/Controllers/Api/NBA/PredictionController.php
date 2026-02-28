<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\NBA\PredictionResource;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;

    protected function returnFirstPredictionOnly(): bool
    {
        return true;
    }
}
