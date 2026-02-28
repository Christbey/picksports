<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\NBA\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'nba_predictions';

    protected const GAMES_TABLE = 'nba_games';
}
