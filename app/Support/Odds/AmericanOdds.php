<?php

namespace App\Support\Odds;

class AmericanOdds
{
    public static function impliedProbability(int $price): ?float
    {
        if ($price === 0) {
            return null;
        }

        if ($price > 0) {
            return 100 / ($price + 100);
        }

        return abs($price) / (abs($price) + 100);
    }

    /**
     * @return array{home:?float,away:?float}
     */
    public static function noVigProbabilities(?int $homePrice, ?int $awayPrice): array
    {
        $home = $homePrice !== null ? self::impliedProbability($homePrice) : null;
        $away = $awayPrice !== null ? self::impliedProbability($awayPrice) : null;
        $sum = ($home ?? 0.0) + ($away ?? 0.0);

        if ($home === null || $away === null || $sum <= 0.0) {
            return ['home' => null, 'away' => null];
        }

        return [
            'home' => $home / $sum,
            'away' => $away / $sum,
        ];
    }
}
