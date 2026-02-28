<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\WNBA\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'wnba_predictions';

    protected const GAMES_TABLE = 'wnba_games';
}
