<?php

namespace App\Actions\ESPN\MLB\Concerns;

use App\Support\ESPN\ObservedStatValue;

trait ParsesMlbStatValues
{
    protected function normalizeInningsPitched(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || preg_match('/\A([0-9]+)(?:\.([012]))?\z/', (string) $value, $parts) !== 1) {
            throw new \DomainException('Unsupported innings observation.');
        }
        $whole = ObservedStatValue::integer($parts[1]);

        return $whole + ((int) ($parts[2] ?? 0) / 3);
    }
}
