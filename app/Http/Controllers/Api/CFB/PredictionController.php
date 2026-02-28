<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\CFB\PredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;
}
