<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\MLB\PredictionResource;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;
}
