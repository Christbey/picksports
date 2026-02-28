<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait CastsEspnValues
{
    protected static function boolOrFalse(mixed $value): bool
    {
        return (bool) ($value ?? false);
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    protected static function intOrZero(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    protected static function stringOrEmpty(mixed $value): string
    {
        return (string) ($value ?? '');
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
