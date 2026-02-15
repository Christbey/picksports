<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\CFB\Prediction;
use App\Models\CFB\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected function getSport(): string
    {
        return 'cfb';
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
        $homeFieldAdvantage = $game->neutral_site ? 0 : config('cfb.elo.home_field_advantage');
        $pointsPerElo = config('cfb.predictions.points_per_elo');
        $eloDiff = ($homeElo + $homeFieldAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff * $pointsPerElo, 1);

        // Clamp spread to configured limits
        $maxSpread = config('cfb.predictions.max_spread');
        $minSpread = config('cfb.predictions.min_spread');
        $predictedSpread = max($minSpread, min($maxSpread, $predictedSpread));

        return $predictedSpread;
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Calculate predicted total
        return config('cfb.predictions.average_total');
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
        // Get FPI ratings if available
        $homeFpi = $homeMetrics?->fpi ?? null;
        $awayFpi = $awayMetrics?->fpi ?? null;

        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_fpi' => $homeFpi,
            'away_fpi' => $awayFpi,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ];
    }

    protected function calculateConfidence(?Model $homeMetrics, ?Model $awayMetrics, int $homeElo, int $awayElo): float
    {
        $defaultElo = config('cfb.elo.default_rating');
        $confidenceConfig = config('cfb.predictions.confidence');
        $confidence = $confidenceConfig['base'];

        // Bonus for non-default Elo ratings (teams have played games)
        if ($homeElo !== $defaultElo) {
            $confidence += $confidenceConfig['home_non_default_elo'];
        }

        if ($awayElo !== $defaultElo) {
            $confidence += $confidenceConfig['away_non_default_elo'];
        }

        return round(min($confidence, 100), 2);
    }
}
