<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractAmericanFootballPredictionGenerator;
use App\Models\CFB\Prediction;
use App\Models\CFB\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractAmericanFootballPredictionGenerator
{
    protected const SPORT_KEY = 'cfb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected function buildPredictionData(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability,
        float $confidenceScore
    ): array {
        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_fpi' => $homeMetrics?->fpi,
            'away_fpi' => $awayMetrics?->fpi,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ];
    }

}
