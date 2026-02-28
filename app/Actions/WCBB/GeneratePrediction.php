<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    protected const SPORT_KEY = 'wcbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;
}
