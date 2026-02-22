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
        $defaultEfficiency = config('wcbb.prediction.default_efficiency');
        $averagePace = config('wcbb.prediction.average_pace');

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

        // Get tempo — adj_tempo is normalized around 100, raw tempo is actual possessions
        $homeHasAdjTempo = $homeMetrics?->adj_tempo !== null;
        $awayHasAdjTempo = $awayMetrics?->adj_tempo !== null;

        if ($homeHasAdjTempo || $awayHasAdjTempo) {
            // Use normalized adj_tempo: convert to possessions via averagePace
            $homeTempo = $homeMetrics?->adj_tempo ?? 100;
            $awayTempo = $awayMetrics?->adj_tempo ?? 100;
            $gameTempo = ($homeTempo + $awayTempo) / 2;
            $possessionsPerGame = $averagePace * ($gameTempo / 100);
        } else {
            // Raw tempo IS possessions per game — use directly
            $homeTempo = $homeMetrics?->tempo ?? $averagePace;
            $awayTempo = $awayMetrics?->tempo ?? $averagePace;
            $possessionsPerGame = ($homeTempo + $awayTempo) / 2;
        }

        // Matchup formula: average each offense against opposing defense
        $homeExpectedEfficiency = ($homeOffEff + $awayDefEff) / 2;
        $awayExpectedEfficiency = ($awayOffEff + $homeDefEff) / 2;

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

    protected function calculateConfidence(float $winProbability): float
    {
        return round(max($winProbability, 1 - $winProbability) * 100, 2);
    }
}
