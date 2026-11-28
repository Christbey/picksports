<?php

namespace App\Support\ESPN;

use DomainException;

/** Source observations only: no missing-value defaults or numeric substring extraction. */
final class ObservedStatValue
{
    public static function integer(mixed $value, bool $signed = false): int
    {
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new DomainException('Invalid integer observation.');
            }
        } elseif ((! is_int($value) && ! is_string($value))
            || preg_match($signed ? '/\A-?[0-9]+\z/' : '/\A[0-9]+\z/', trim((string) $value)) !== 1) {
            throw new DomainException('Invalid integer observation.');
        }
        $number = (float) $value;
        if (! is_finite($number) || $number < ($signed ? -2147483648 : 0) || $number > 2147483647) {
            throw new DomainException('Integer observation exceeds storage capacity.');
        }

        return (int) $value;
    }

    public static function decimal(mixed $value, bool $signed = false, ?float $maximum = null): float
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)
            || ! is_finite((float) $value) || (! $signed && (float) $value < 0) || ($maximum !== null && (float) $value > $maximum)) {
            throw new DomainException('Invalid decimal observation.');
        }

        return (float) $value;
    }

    /** @return array{int,int} */
    public static function pair(mixed $value, string $separator = '/', bool $ordered = true): array
    {
        if (! is_string($value) || preg_match('/\A([0-9]+)'.preg_quote($separator, '/').'([0-9]+)\z/', trim($value), $parts) !== 1) {
            throw new DomainException('Invalid paired observation.');
        }
        $first = self::integer($parts[1]);
        $second = self::integer($parts[2]);
        if ($ordered && $first > $second) {
            throw new DomainException('Paired observations are inconsistent.');
        }

        return [$first, $second];
    }

    /** @return array<string,mixed> */
    public static function labeled(array $labels, array $stats, bool $alphanumeric = false): array
    {
        if ($labels === [] || ! array_is_list($labels) || ! array_is_list($stats) || count($labels) !== count($stats)) {
            throw new DomainException('Missing or incomplete source labels.');
        }
        $mapped = [];
        foreach ($labels as $index => $label) {
            if (! is_string($label)) {
                throw new DomainException('Invalid source label.');
            }
            $label = strtoupper(trim($label));
            if ($alphanumeric) {
                $label = preg_replace('/[^A-Z0-9]/', '', $label);
            }
            if ($label === '' || array_key_exists($label, $mapped)) {
                throw new DomainException('Empty or ambiguous source labels.');
            }
            $mapped[$label] = $stats[$index];
        }

        return $mapped;
    }
}
