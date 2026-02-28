<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\NFL\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'nfl_predictions';

    protected const GAMES_TABLE = 'nfl_games';
}
