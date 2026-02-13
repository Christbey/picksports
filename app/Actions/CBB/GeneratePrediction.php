<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\TeamMetric;

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
        $defaultElo = config('cbb.elo.default');
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
        $defaultEfficiency = config('cbb.prediction.default_efficiency');
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

        // Calculate Elo-based spread
        $homeCourtAdvantage = config('cbb.elo.home_court_advantage');
        $eloToSpread = config('cbb.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;
        $eloSpread = $eloDiff / $eloToSpread;

        // Calculate efficiency-based spread using matchup formula
        // Home margin = (HomeOff - AwayDef) - (AwayOff - HomeDef) + HCA
        // Simplified: (HomeOff + HomeDef) - (AwayOff + AwayDef) + HCA (with efficiency)
        // Actually: Home scores at (HomeOff * AwayDef / 100), Away scores at (AwayOff * HomeDef / 100)
        $homeNetRating = $homeOffEff - $homeDefEff;
        $awayNetRating = $awayOffEff - $awayDefEff;
        $homeCourtPoints = config('cbb.prediction.home_court_points');
        $efficiencySpread = (($homeNetRating - $awayNetRating) / 2) + $homeCourtPoints;

        // Blend Elo and efficiency spreads
        $hasValidMetrics = $homeMetrics?->meets_minimum && $awayMetrics?->meets_minimum;
        $eloWeight = config('cbb.prediction.elo_weight');
        if ($hasValidMetrics) {
            // Use blended spread when we have good efficiency data
            $predictedSpread = round(
                ($eloWeight * $eloSpread) + ((1 - $eloWeight) * $efficiencySpread),
                1
            );
        } else {
            // Fall back to Elo-only when metrics are insufficient
            $predictedSpread = round($eloSpread, 1);
        }

        // Calculate predicted total using MATCHUP-based efficiency
        // Home team scores: HomeOff vs AwayDef (adjusted for tempo)
        // Away team scores: AwayOff vs HomeDef (adjusted for tempo)
        $homeTempo = $homeMetrics->adj_tempo ?? $homeMetrics->tempo ?? $defaultEfficiency;
        $awayTempo = $awayMetrics->adj_tempo ?? $awayMetrics->tempo ?? $defaultEfficiency;

        // Average tempo for the game (both teams influence pace)
        $gameTempo = ($homeTempo + $awayTempo) / 2;

        // Convert normalized tempo to possessions per game
        // gameTempo of 100 = average_pace possessions
        $averagePace = config('cbb.prediction.average_pace');
        $possessionsPerGame = $averagePace * ($gameTempo / 100);

        // Calculate expected points using matchup formula
        // Home team offense vs away team defense: (HomeOff + AwayDef) / 2
        // This accounts for the interaction between offense and defense
        $homeExpectedEfficiency = ($homeOffEff + $awayDefEff) / 2;
        $awayExpectedEfficiency = ($awayOffEff + $homeDefEff) / 2;

        // Points = efficiency * (possessions / 100)
        $homePredictedScore = $homeExpectedEfficiency * ($possessionsPerGame / 100);
        $awayPredictedScore = $awayExpectedEfficiency * ($possessionsPerGame / 100);

        $predictedTotal = round($homePredictedScore + $awayPredictedScore, 1);

        // Calculate win probability from spread using calibrated logistic function
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
        // Calibrated so 7-point spread ≈ 78% win probability
        $coefficient = config('cbb.prediction.spread_to_probability_coefficient');
        $probability = 1 / (1 + exp(-$spread / $coefficient));

        return round($probability, 3);
    }

    protected function calculateConfidence(?TeamMetric $homeMetrics, ?TeamMetric $awayMetrics, int $homeElo, int $awayElo): float
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
