<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** New billing tables always store/interpret naive SQL timestamps as UTC, independently of app timezone. */
trait UsesBillingUtcDates
{
    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC');
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value, 'UTC');
        }

        return Carbon::parse($value, 'UTC');
    }

    public function freshTimestamp()
    {
        return now('UTC');
    }
}
