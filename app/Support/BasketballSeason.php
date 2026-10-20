<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class BasketballSeason
{
    public static function forDate(?CarbonInterface $date = null): int
    {
        $date = CarbonImmutable::instance($date ?? CarbonImmutable::now('America/Chicago'))->setTimezone('America/Chicago');

        return (int) $date->year + ($date->month >= 7 ? 1 : 0);
    }
}
