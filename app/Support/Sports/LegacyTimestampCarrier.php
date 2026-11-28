<?php

namespace App\Support\Sports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Application-wall carrier for existing TIMESTAMP(0) columns, not UTC6 evidence. */
final class LegacyTimestampCarrier
{
    public static function forInstant(CarbonInterface $value): ?CarbonImmutable
    {
        $instant = CarbonImmutable::instance($value);
        $timezone = (string) config('app.timezone', 'UTC');
        $wall = $instant->setTimezone($timezone);
        $literal = $wall->format('Y-m-d H:i:s');
        $wallEpoch = CarbonImmutable::parse($literal, 'UTC')->getTimestamp();
        if ($instant->getTimestamp() < 1 || $instant->getTimestamp() > 2147483647 || $wallEpoch < 1 || $wallEpoch > 2147483647) {
            return null;
        }
        $roundTrip = CarbonImmutable::parse($literal, $timezone);

        // Existing columns retain seconds only. The same second must survive their wall-clock cast.
        return $roundTrip->getTimestamp() === $instant->getTimestamp() ? $wall : null;
    }
}
