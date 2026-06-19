<?php

namespace App\Support\MLB;

class MlbMarketSpread
{
    public static function edgeRuns(float $modelHomeMargin, float $vegasHomeSpread): float
    {
        return $modelHomeMargin + $vegasHomeSpread;
    }

    public static function marketLineFromHomeSpread(float $vegasHomeSpread): float
    {
        return -1 * $vegasHomeSpread;
    }
}
