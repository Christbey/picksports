<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\NBA\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'nba';

    protected const ELO_RATING_MODEL = EloRating::class;

    protected function calculateKFactor(Model $game): float
    {
        return $this->calculateStandardKFactor($game);
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === config('nba.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('nba.elo.margin_multipliers', []);

        return $this->resolveMarginMultiplier($margin, $multipliers);
    }
}
