<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\NFL\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected function getSport(): string
    {
        return 'nfl';
    }

    protected function getEloRatingModel(): string
    {
        return EloRating::class;
    }

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('nfl.elo.base_k_factor');

        // Increase K-factor early in season (more volatility as teams find identity)
        if ($game->week && $game->week <= config('nfl.elo.recency_weeks') && $game->season_type === 'Regular Season') {
            $kFactor *= config('nfl.elo.recency_multiplier');
        }

        // Apply playoff multiplier
        if ($this->isPlayoffGame($game)) {
            $kFactor *= config('nfl.elo.playoff_multiplier');
        }

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === 'Postseason';
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);

        // Logarithmic formula with diminishing returns
        // Formula: 1.0 + (log(margin + 1) * coefficient)
        // This prevents blowouts from dominating too much
        $coefficient = config('nfl.elo.mov_coefficient');
        $maxMultiplier = config('nfl.elo.max_mov_multiplier');

        return min($maxMultiplier, 1.0 + (log($margin + 1) * $coefficient));
    }
}
