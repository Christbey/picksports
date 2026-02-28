<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\WCBB\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'wcbb_predictions';

    protected const GAMES_TABLE = 'wcbb_games';
}
