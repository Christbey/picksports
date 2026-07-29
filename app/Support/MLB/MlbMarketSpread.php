<?php

namespace App\Support\MLB;

use App\Support\Odds\MarketSpread;

class MlbMarketSpread
{
    public static function edgeRuns(float $modelHomeMargin, float $vegasHomeSpread): float
    {
        return MarketSpread::edge($modelHomeMargin, $vegasHomeSpread);
    }

    public static function marketLineFromHomeSpread(float $vegasHomeSpread): float
    {
        return MarketSpread::bookmakerHomeLineToHomeMargin($vegasHomeSpread);
    }
}
