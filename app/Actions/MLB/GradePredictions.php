<?php

namespace App\Actions\MLB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\MLB\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'mlb_predictions';

    protected const GAMES_TABLE = 'mlb_games';
}
