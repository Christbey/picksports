<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\NFL\Prediction;
use App\Models\NFL\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected function getSport(): string
    {
        return 'nfl';
    }

    protected function getTeamMetricModel(): string
    {
        return TeamMetric::class;
    }

    protected function getPredictionModel(): string
    {
        return Prediction::class;
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Calculate predicted spread (negative means away team favored)
        $homeFieldAdvantage = $game->neutral_site ? 0 : config('nfl.elo.home_field_advantage');
        $pointsPerElo = config('nfl.predictions.points_per_elo');
        $eloDiff = ($homeElo + $homeFieldAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff * $pointsPerElo, 1);

        // Clamp spread to configured limits
        $maxSpread = config('nfl.predictions.max_spread');
        $minSpread = config('nfl.predictions.min_spread');
        $predictedSpread = max($minSpread, min($maxSpread, $predictedSpread));

        return $predictedSpread;
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Calculate predicted total (over/under)
        return config('nfl.predictions.average_total');
    }
}
