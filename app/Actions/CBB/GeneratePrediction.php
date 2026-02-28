<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    protected const SPORT_KEY = 'cbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;
}
