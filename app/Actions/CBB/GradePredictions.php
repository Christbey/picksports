<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CBB\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cbb_predictions';

    protected const GAMES_TABLE = 'cbb_games';
}
