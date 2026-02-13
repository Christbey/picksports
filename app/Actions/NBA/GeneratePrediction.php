<?php

namespace App\Actions\NBA;

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\TeamMetric;

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
        $defaultElo = config('nba.elo.default');
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

        // Extract efficiency metrics (use league averages if not available)
        $defaultEfficiency = config('nba.prediction.default_efficiency');
        $homeOffEff = $homeMetrics->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics->defensive_efficiency ?? $defaultEfficiency;

        // Calculate predicted spread (negative means away team favored)
        $homeCourtAdvantage = config('nba.elo.home_court_advantage');
        $eloToSpread = config('nba.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff / $eloToSpread, 1);

        // Calculate predicted total using efficiency metrics
        // Formula: ((homeOffEff + awayDefEff) / 2 + (awayOffEff + homeDefEff) / 2) * (pace / 100)
        $homePredictedScore = ($homeOffEff + $awayDefEff) / 2;
        $awayPredictedScore = ($awayOffEff + $homeDefEff) / 2;
        $pace = $homeMetrics->tempo ?? $awayMetrics->tempo ?? config('nba.prediction.average_pace');
        $predictedTotal = round(($homePredictedScore + $awayPredictedScore) * ($pace / 100), 1);

        // Calculate win probability from spread
        // Using a logistic function calibrated for NBA spreads
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
        // Logistic function calibrated so 7-point spread ≈ 70% win probability
        $coefficient = config('nba.prediction.spread_to_probability_coefficient');
        $probability = 1 / (1 + exp(-$spread / $coefficient));

        return round($probability, 3);
    }

    protected function calculateConfidence(?TeamMetric $homeMetrics, ?TeamMetric $awayMetrics, int $homeElo, int $awayElo): float
    {
        $confidenceConfig = config('nba.prediction.confidence');
        $defaultElo = config('nba.elo.default');
        $confidence = 0;

        // Base confidence from having Elo data
        $confidence += $confidenceConfig['base'];

        // Bonus for having team metrics
        if ($homeMetrics) {
            $confidence += $confidenceConfig['home_metrics'];
        }

        if ($awayMetrics) {
            $confidence += $confidenceConfig['away_metrics'];
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
