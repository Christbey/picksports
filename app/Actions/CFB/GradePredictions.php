<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CFB\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cfb_predictions';

    protected const GAMES_TABLE = 'cfb_games';
}
