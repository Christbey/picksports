<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected function getSport(): string
    {
        return 'wcbb';
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
        $homeCourtAdvantage = config('wcbb.elo.home_court_advantage');
        $eloToSpread = config('wcbb.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;

        return round($eloDiff / $eloToSpread, 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Extract efficiency metrics (prefer adjusted, fall back to raw, then league average)
        $defaultEfficiency = config('wcbb.prediction.default_efficiency');
        $homeOffEff = $homeMetrics?->adj_offensive_efficiency
            ?? $homeMetrics?->offensive_efficiency
            ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->adj_offensive_efficiency
            ?? $awayMetrics?->offensive_efficiency
            ?? $defaultEfficiency;

        // Calculate predicted total using efficiency metrics
        // Use adjusted tempo if available, fall back to raw tempo, then league average
        $normalizedTempo = $homeMetrics?->adj_tempo
            ?? $homeMetrics?->tempo
            ?? $awayMetrics?->adj_tempo
            ?? $awayMetrics?->tempo
            ?? $defaultEfficiency;

        // Convert normalized tempo to actual possessions and calculate scores
        $averagePace = config('wcbb.prediction.average_pace');
        $tempoFactor = ($averagePace / 100) * ($normalizedTempo / 100);
        $homePredictedScore = $homeOffEff * $tempoFactor;
        $awayPredictedScore = $awayOffEff * $tempoFactor;

        return round($homePredictedScore + $awayPredictedScore, 1);
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
        // Extract efficiency metrics for storage
        $defaultEfficiency = config('wcbb.prediction.default_efficiency');
        $homeOffEff = $homeMetrics?->adj_offensive_efficiency
            ?? $homeMetrics?->offensive_efficiency
            ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->adj_defensive_efficiency
            ?? $homeMetrics?->defensive_efficiency
            ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->adj_offensive_efficiency
            ?? $awayMetrics?->offensive_efficiency
            ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->adj_defensive_efficiency
            ?? $awayMetrics?->defensive_efficiency
            ?? $defaultEfficiency;

        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_off_eff' => $homeOffEff,
            'home_def_eff' => $homeDefEff,
            'away_off_eff' => $awayOffEff,
            'away_def_eff' => $awayDefEff,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ];
    }

    protected function calculateConfidence(?Model $homeMetrics, ?Model $awayMetrics, int $homeElo, int $awayElo): float
    {
        $confidenceConfig = config('wcbb.prediction.confidence');
        $defaultElo = config('wcbb.elo.default');
        $confidence = 0;

        // Base confidence from having Elo data (always have this)
        $confidence += $confidenceConfig['base'];

        // Bonus for having team metrics
        if ($homeMetrics) {
            $confidence += $confidenceConfig['home_metrics'];

            // Additional bonus for having adjusted metrics (opponent-adjusted data)
            if ($homeMetrics->adj_offensive_efficiency !== null) {
                $confidence += $confidenceConfig['home_adjusted_metrics'];
            }
        }

        if ($awayMetrics) {
            $confidence += $confidenceConfig['away_metrics'];

            // Additional bonus for having adjusted metrics (opponent-adjusted data)
            if ($awayMetrics->adj_offensive_efficiency !== null) {
                $confidence += $confidenceConfig['away_adjusted_metrics'];
            }
        }

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
