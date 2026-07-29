<?php

namespace App\Support\Odds;

final class MarketSpread
{
    public const BOOKMAKER_HOME_LINE_CONVENTION = 'bookmaker_home_line_negative_favorite';

    public const HOME_MARGIN_CONVENTION = 'home_margin_positive_home';

    public static function bookmakerHomeLineToHomeMargin(float $bookmakerHomeLine): float
    {
        return -1 * $bookmakerHomeLine;
    }

    public static function homeMarginToBookmakerHomeLine(float $homeMargin): float
    {
        return -1 * $homeMargin;
    }

    public static function edge(float $modelHomeMargin, float $bookmakerHomeLine): float
    {
        return $modelHomeMargin - self::bookmakerHomeLineToHomeMargin($bookmakerHomeLine);
    }
}
