<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractAmericanFootballPredictionGenerator;
use App\Models\NFL\Prediction;
use App\Models\NFL\TeamMetric;

class GeneratePrediction extends AbstractAmericanFootballPredictionGenerator
{
    protected const SPORT_KEY = 'nfl';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;
}
