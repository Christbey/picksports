<?php

namespace App\Services\BettingRecommendations;

/** Nonnegative count distributions preserve the projected mean without truncating a bell curve. */
class NflPropCountDistribution
{
    public function outcomes(float $mean, float $line, float $stdDev, bool $anytimeTouchdown = false): array
    {
        $mean = max(0.0, $mean);
        $variance = max($mean, $stdDev ** 2);
        $overdispersed = $variance > $mean + 0.000001 && $mean > 0;
        $shape = $overdispersed ? $mean ** 2 / ($variance - $mean) : null;
        $p = $overdispersed ? $shape / ($shape + $mean) : null;
        $mass = $overdispersed ? pow($p, $shape) : exp(-$mean);
        $under = $over = $push = 0.0;
        $limit = (int) ceil(max(100, $line + 1, $mean + 40 * sqrt($variance)));
        for ($k = 0; $k <= $limit; $k++) {
            $value = $anytimeTouchdown ? min(1, $k) : $k;
            if ($value > $line) {
                $over += $mass;
            } elseif ($value < $line) {
                $under += $mass;
            } else {
                $push += $mass;
            }
            $mass *= $overdispersed ? (($k + $shape) / ($k + 1)) * (1 - $p) : $mean / ($k + 1);
        }
        // The residual upper tail is still above any supported line, not push mass.
        $tail = max(0.0, 1 - $under - $over - $push);
        if (! $anytimeTouchdown || $line < 1) {
            $over += $tail;
        } elseif ($line == 1) {
            $push += $tail;
        } else {
            $under += $tail;
        }

        return ['over' => $over, 'under' => $under, 'push' => $push];
    }
}
