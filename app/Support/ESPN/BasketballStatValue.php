<?php

namespace App\Support\ESPN;

final class BasketballStatValue
{
    public static function integer(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/\A[0-9]+\z/', $value) !== 1 || (float) $value > 2147483647) {
            return null;
        }

        return (int) $value;
    }

    /** @return array{int,int}|null */
    public static function madeAttempt(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/\A([0-9]+)-([0-9]+)\z/', trim($value), $parts) !== 1) {
            return null;
        }
        $made = self::integer($parts[1]);
        $attempted = self::integer($parts[2]);

        return $made !== null && $attempted !== null && $made <= $attempted ? [$made, $attempted] : null;
    }
}
