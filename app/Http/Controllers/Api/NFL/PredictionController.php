<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPredictionController;
use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;

class PredictionController extends AbstractPredictionController
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const PREDICTION_RESOURCE = PredictionResource::class;
}
