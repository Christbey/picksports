<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

final class BillingClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now('UTC'));
    }

    public static function fromEpoch(?int $epoch): ?CarbonImmutable
    {
        return $epoch === null ? null : CarbonImmutable::createFromTimestampUTC($epoch);
    }
}
