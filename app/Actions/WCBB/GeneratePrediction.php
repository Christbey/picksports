<?php

namespace App\Actions\WCBB;

use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;

class GeneratePrediction
{
    public function execute(Game $game): ?Prediction
    {
        // Don't predict games that are already completed
        if ($game->status === 'STATUS_FINAL') {
            return null;
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        // Get current Elo ratings
        $defaultElo = config('wcbb.elo.default');
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Get team metrics for the season
        $homeMetrics = TeamMetric::query()
            ->where('team_id', $homeTeam->id)
            ->where('season', $game->season)
            ->first();

        $awayMetrics = TeamMetric::query()
            ->where('team_id', $awayTeam->id)
            ->where('season', $game->season)
            ->first();

        // Extract efficiency metrics (prefer adjusted, fall back to raw, then league average)
        $defaultEfficiency = config('wcbb.prediction.default_efficiency');
        $homeOffEff = $homeMetrics->adj_offensive_efficiency
            ?? $homeMetrics->offensive_efficiency
            ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics->adj_defensive_efficiency
            ?? $homeMetrics->defensive_efficiency
            ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics->adj_offensive_efficiency
            ?? $awayMetrics->offensive_efficiency
            ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics->adj_defensive_efficiency
            ?? $awayMetrics->defensive_efficiency
            ?? $defaultEfficiency;

        // Calculate predicted spread (negative means away team favored)
        $homeCourtAdvantage = config('wcbb.elo.home_court_advantage');
        $eloToSpread = config('wcbb.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff / $eloToSpread, 1);

        // Calculate predicted total using efficiency metrics
        // Efficiency metrics are points per 100 possessions (normalized to 100)
        // Tempo metrics are also normalized to 100 (100 = average_pace possessions)
        // Use adjusted tempo if available, fall back to raw tempo, then league average
        $normalizedTempo = $homeMetrics->adj_tempo
            ?? $homeMetrics->tempo
            ?? $awayMetrics->adj_tempo
            ?? $awayMetrics->tempo
            ?? $defaultEfficiency; // If no tempo data, use 100 (league average)

        // Convert normalized tempo to actual possessions: 70 * (normalizedTempo / 100)
        // Then calculate score: offEff * (actualPossessions / 100)
        // Simplified: offEff * 0.7 * (normalizedTempo / 100)
        $averagePace = config('wcbb.prediction.average_pace');
        $tempoFactor = ($averagePace / 100) * ($normalizedTempo / 100);
        $homePredictedScore = $homeOffEff * $tempoFactor;
        $awayPredictedScore = $awayOffEff * $tempoFactor;
        $predictedTotal = round($homePredictedScore + $awayPredictedScore, 1);

        // Calculate win probability from spread
        // Using a logistic function calibrated for college basketball spreads
        $winProbability = $this->calculateWinProbability($predictedSpread);

        // Calculate confidence score based on data quality
        $confidenceScore = $this->calculateConfidence($homeMetrics, $awayMetrics, $homeElo, $awayElo);

        // Create or update prediction
        return Prediction::updateOrCreate(
            ['game_id' => $game->id],
            [
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
            ]
        );
    }

    protected function calculateWinProbability(float $spread): float
    {
        // Logistic function: 1 / (1 + e^(-spread/coefficient))
        // Calibrated so 7-point spread ≈ 70% probability
        $coefficient = config('wcbb.prediction.spread_to_probability_coefficient');
        $probability = 1 / (1 + exp(-$spread / $coefficient));

        return round($probability, 3);
    }

    protected function calculateConfidence(?TeamMetric $homeMetrics, ?TeamMetric $awayMetrics, int $homeElo, int $awayElo): float
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
