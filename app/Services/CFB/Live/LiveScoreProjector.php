<?php

namespace App\Services\CFB\Live;

/** Coherent regulation score estimates; rate weights and probabilities are provisional, not calibrated. */
class LiveScoreProjector
{
    public const VERSION = 'cfb-live-score-clock-v2';

    public function project(int $home, int $away, int $remaining, float $margin, float $total, ?float $pregameProbability = null): array
    {
        if ($home < 0 || $away < 0 || $remaining < 0 || $remaining > 3600
            || ! is_finite($margin) || ! is_finite($total) || $total < abs($margin)) {
            throw new \InvalidArgumentException('Invalid regulation score or pregame baseline.');
        }
        $fraction = 1 - $remaining / 3600;
        // One game's prior exposure plus the observed fraction of this game.
        // This avoids extrapolating an early touchdown into an entire game's pace.
        $homeRemaining = (($total + $margin) / 2 + $home) / (1 + $fraction) * (1 - $fraction);
        $awayRemaining = (($total - $margin) / 2 + $away) / (1 + $fraction) * (1 - $fraction);
        $projectedTotal = $home + $away + $homeRemaining + $awayRemaining;
        // Preserve the existing margin estimator. Constrain it to the feasible
        // score interval rather than allowing either team's score to decrease.
        $currentMargin = $home - $away;
        $rawMargin = $currentMargin + $margin * (1 - $fraction) ** 2
            + ($fraction > 0 ? $currentMargin * (1 - $fraction) * .3 : 0);
        $rawMargin = $rawMargin * (1 - $fraction ** 2) + $currentMargin * $fraction ** 2;
        $projectedMargin = max(2 * $home - $projectedTotal, min($projectedTotal - 2 * $away, $rawMargin));
        $projectedHome = round(($projectedTotal + $projectedMargin) / 2, 1);
        $projectedAway = round(($projectedTotal - $projectedMargin) / 2, 1);
        // Retain v1's probability heuristic: a replacement failed the historical
        // replay. This is not an ATS probability or a calibrated betting edge.
        $prior = $pregameProbability ?? 1 / (1 + exp(-max(-700, min(700, $margin / 6))));
        $prior = max(.01, min(.99, $prior));
        $logOdds = log($prior / (1 - $prior)) * (1 - sqrt($fraction))
            + $currentMargin * (.02 + .15 * $fraction ** 2);
        $probability = $remaining === 0 ? ($currentMargin > 0 ? .999 : ($currentMargin < 0 ? .001 : .5))
            : 1 / (1 + exp(-max(-700, min(700, $logOdds))));

        return ['spread' => round($projectedHome - $projectedAway, 1), 'total' => round($projectedHome + $projectedAway, 1),
            'home_points' => round($projectedHome, 1), 'away_points' => round($projectedAway, 1),
            'home_win_probability' => round(max(.001, min(.999, $probability)), 3),
            'seconds_remaining' => $remaining, 'model_version' => self::VERSION,
            'probability_status' => 'uncalibrated_model_estimate'];
    }
}
