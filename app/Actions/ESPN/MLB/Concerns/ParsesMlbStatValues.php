<?php

namespace App\Actions\ESPN\MLB\Concerns;

trait ParsesMlbStatValues
{
    protected function intAt(array $stats, int $index): ?int
    {
        $value = $stats[$index] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    protected function floatAt(array $stats, int $index): ?float
    {
        $value = $stats[$index] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    protected function parseDisplayStatValue(mixed $value): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return str_contains((string) $value, '.')
            ? (float) $value
            : (int) $value;
    }
}
