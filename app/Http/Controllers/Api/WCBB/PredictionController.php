<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\WCBB\PredictionResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;
}
