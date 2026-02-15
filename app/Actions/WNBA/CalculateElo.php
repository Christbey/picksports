<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\WNBA\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected function getSport(): string
    {
        return 'wnba';
    }

    protected function getEloRatingModel(): string
    {
        return EloRating::class;
    }

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('wnba.elo.base_k_factor');

        // Apply playoff multiplier
        if ($this->isPlayoffGame($game)) {
            $kFactor *= config('wnba.elo.playoff_multiplier');
        }

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === config('wnba.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('wnba.elo.margin_multipliers');

        foreach ($multipliers as $tier) {
            if ($tier['max_margin'] === null || $margin <= $tier['max_margin']) {
                return $tier['multiplier'];
            }
        }

        return 1.0;
    }
}
