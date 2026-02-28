<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractAmericanFootballPredictionGenerator extends AbstractPredictionGenerator
{
    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $sport = $this->getSport();

        $homeFieldAdvantage = $game->neutral_site ? 0 : config("{$sport}.elo.home_field_advantage");
        $pointsPerElo = config("{$sport}.predictions.points_per_elo");

        $eloDiff = ($homeElo + $homeFieldAdvantage) - $awayElo;
        $predictedSpread = round($eloDiff * $pointsPerElo, 1);

        $maxSpread = config("{$sport}.predictions.max_spread");
        $minSpread = config("{$sport}.predictions.min_spread");

        return max($minSpread, min($maxSpread, $predictedSpread));
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        return config("{$this->getSport()}.predictions.average_total");
    }
}
