<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\NFL\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'nfl';

    protected const ELO_RATING_MODEL = EloRating::class;

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('nfl.elo.base_k_factor');

        $kFactor = $this->applyRecencyWeekMultiplier($game, (float) $kFactor, 'Regular Season');

        $kFactor = $this->applyPlayoffMultiplier($game, (float) $kFactor);

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === config('nfl.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $coefficient = config('nfl.elo.mov_coefficient');
        $maxMultiplier = config('nfl.elo.max_mov_multiplier');

        return $this->resolveLogMarginMultiplier($margin, (float) $coefficient, (float) $maxMultiplier);
    }
}
