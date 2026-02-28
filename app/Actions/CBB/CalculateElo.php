<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\CBB\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'cbb';

    protected const ELO_RATING_MODEL = EloRating::class;

    protected function calculateKFactor(Model $game): float
    {
        return $this->calculateStandardKFactor($game);
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type === config('cbb.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('cbb.elo.margin_multipliers', []);

        return $this->resolveMarginMultiplier($margin, $multipliers);
    }

    protected function calculateSosAdjustment(int $homeElo, int $awayElo): float
    {
        $config = config('cbb.elo.sos_adjustment');

        if (! $config['enabled']) {
            return 1.0;
        }

        $eloGap = abs($homeElo - $awayElo);
        $dampener = 1.0 - ($eloGap / $config['divisor']);

        return max($config['floor'], $dampener);
    }
}
