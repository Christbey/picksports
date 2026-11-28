<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

final class BillingTime
{
    /** Compatibility mirror only; canonical marked rows read exact epochs instead of this ambiguous wall clock. */
    public static function mirror(?int $epoch): ?CarbonImmutable
    {
        return $epoch === null ? null : CarbonImmutable::createFromTimestampUTC($epoch)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Cashier SQL columns are naive application-timezone dates; preserve the provider instant. */
    public static function sql(?int $epoch): ?CarbonImmutable
    {
        if ($epoch === null) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $local = CarbonImmutable::createFromTimestampUTC($epoch)->setTimezone($timezone);
        // Cashier persists no offset. Refuse the repeated-hour instant its ordinary cast cannot recover.
        $roundtrip = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local->format('Y-m-d H:i:s'), $timezone);
        if ($roundtrip === false || $roundtrip->getTimestamp() !== $epoch) {
            throw new BillingProviderFailure('provider_timestamp_not_representable');
        }

        return $local;
    }
}
