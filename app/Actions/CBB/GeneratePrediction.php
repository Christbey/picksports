<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\CBB\Prediction;
use App\Models\CBB\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected function getSport(): string
    {
        return 'cbb';
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
        // Extract efficiency metrics (prefer adjusted, fall back to raw, then league average)
        $defaultEfficiency = config('cbb.prediction.default_efficiency');
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

        // Calculate Elo-based spread
        $homeCourtAdvantage = config('cbb.elo.home_court_advantage');
        $eloToSpread = config('cbb.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;
        $eloSpread = $eloDiff / $eloToSpread;

        // Calculate efficiency-based spread using matchup formula
        $homeNetRating = $homeOffEff - $homeDefEff;
        $awayNetRating = $awayOffEff - $awayDefEff;
        $homeCourtPoints = config('cbb.prediction.home_court_points');
        $efficiencySpread = (($homeNetRating - $awayNetRating) / 2) + $homeCourtPoints;

        // Blend Elo and efficiency spreads
        $hasValidMetrics = $homeMetrics?->meets_minimum && $awayMetrics?->meets_minimum;
        $eloWeight = config('cbb.prediction.elo_weight');
        if ($hasValidMetrics) {
            // Use blended spread when we have good efficiency data
            return round(
                ($eloWeight * $eloSpread) + ((1 - $eloWeight) * $efficiencySpread),
                1
            );
        } else {
            // Fall back to Elo-only when metrics are insufficient
            return round($eloSpread, 1);
        }
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Get efficiency and tempo metrics
        $defaultEfficiency = config('cbb.prediction.default_efficiency');
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

        $homeTempo = $homeMetrics?->adj_tempo ?? $homeMetrics?->tempo ?? $defaultEfficiency;
        $awayTempo = $awayMetrics?->adj_tempo ?? $awayMetrics?->tempo ?? $defaultEfficiency;

        // Average tempo for the game (both teams influence pace)
        $gameTempo = ($homeTempo + $awayTempo) / 2;

        // Convert normalized tempo to possessions per game
        $averagePace = config('cbb.prediction.average_pace');
        $possessionsPerGame = $averagePace * ($gameTempo / 100);

        // Calculate expected points using matchup formula
        $homeExpectedEfficiency = ($homeOffEff + $awayDefEff) / 2;
        $awayExpectedEfficiency = ($awayOffEff + $homeDefEff) / 2;

        // Points = efficiency * (possessions / 100)
        $homePredictedScore = $homeExpectedEfficiency * ($possessionsPerGame / 100);
        $awayPredictedScore = $awayExpectedEfficiency * ($possessionsPerGame / 100);

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
        $defaultEfficiency = config('cbb.prediction.default_efficiency');
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
        $confidenceConfig = config('cbb.prediction.confidence');
        $defaultElo = config('cbb.elo.default');
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
