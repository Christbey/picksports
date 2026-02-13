<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;

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
        $defaultElo = config('nfl.elo.default_rating');
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Calculate predicted spread (negative means away team favored)
        $homeFieldAdvantage = $game->neutral_site ? 0 : config('nfl.elo.home_field_advantage');
        $pointsPerElo = config('nfl.predictions.points_per_elo');
        $eloDiff = ($homeElo + $homeFieldAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff * $pointsPerElo, 1);

        // Clamp spread to configured limits
        $maxSpread = config('nfl.predictions.max_spread');
        $minSpread = config('nfl.predictions.min_spread');
        $predictedSpread = max($minSpread, min($maxSpread, $predictedSpread));

        // Calculate predicted total (over/under)
        $predictedTotal = config('nfl.predictions.average_total');

        // Calculate win probability from Elo difference
        $winProbability = $this->calculateWinProbability($eloDiff);

        // Calculate confidence score based on data quality
        $confidenceScore = $this->calculateConfidence($homeElo, $awayElo);

        // Create or update prediction
        return Prediction::updateOrCreate(
            ['game_id' => $game->id],
            [
                'home_elo' => $homeElo,
                'away_elo' => $awayElo,
                'predicted_spread' => $predictedSpread,
                'predicted_total' => $predictedTotal,
                'win_probability' => $winProbability,
                'confidence_score' => $confidenceScore,
            ]
        );
    }

    protected function calculateWinProbability(float $eloDiff): float
    {
        // Standard Elo probability formula
        $probability = 1 / (1 + pow(10, -$eloDiff / 400));

        return round($probability, 3);
    }

    protected function calculateConfidence(int $homeElo, int $awayElo): float
    {
        $defaultElo = config('nfl.elo.default_rating');
        $confidence = 0;

        // Base confidence
        $confidence += 50;

        // Bonus for non-default Elo ratings (teams have played games)
        if ($homeElo !== $defaultElo) {
            $confidence += 25;
        }

        if ($awayElo !== $defaultElo) {
            $confidence += 25;
        }

        return round(min($confidence, 100), 2);
    }
}
