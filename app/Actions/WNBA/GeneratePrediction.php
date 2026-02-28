<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected const SPORT_KEY = 'wnba';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Calculate predicted spread (negative means away team favored)
        $homeCourtAdvantage = config('wnba.elo.home_court_advantage');
        $eloToSpread = config('wnba.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;

        return round($eloDiff / $eloToSpread, 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Extract efficiency metrics (use league averages if not available)
        $defaultEfficiency = config('wnba.prediction.default_efficiency');
        $homeOffEff = $homeMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->defensive_efficiency ?? $defaultEfficiency;

        // Calculate predicted total using efficiency metrics
        $homePredictedScore = ($homeOffEff + $awayDefEff) / 2;
        $awayPredictedScore = ($awayOffEff + $homeDefEff) / 2;
        $pace = $homeMetrics?->tempo ?? $awayMetrics?->tempo ?? config('wnba.prediction.average_pace');

        return round(($homePredictedScore + $awayPredictedScore) * ($pace / 100), 1);
    }

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
        $defaultEfficiency = config('wnba.prediction.default_efficiency');

        return array_merge(
            parent::buildPredictionData(
                $homeElo,
                $awayElo,
                $homeMetrics,
                $awayMetrics,
                $predictedSpread,
                $predictedTotal,
                $winProbability,
                $confidenceScore
            ),
            $this->efficiencyPredictionData($homeMetrics, $awayMetrics, $defaultEfficiency)
        );
    }
}
