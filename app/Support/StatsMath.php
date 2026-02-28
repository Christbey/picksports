<?php

namespace App\Support;

class StatsMath
{
    public static function percentage(float|int|null $made, float|int|null $attempted, int $precision = 1): float
    {
        $made = (float) ($made ?? 0);
        $attempted = (float) ($attempted ?? 0);

        if ($attempted <= 0) {
            return 0.0;
        }

        return round(($made / $attempted) * 100, $precision);
    }
}
