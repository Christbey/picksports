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

    protected function normalizeInningsPitched(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $whole = (int) floor($number);
            $fractionDigit = (int) round(($number - $whole) * 10);

            if ($fractionDigit === 1 || $fractionDigit === 2) {
                return $whole + ($fractionDigit / 3);
            }

            return $number;
        }

        $text = trim((string) $value);
        if (! preg_match('/^(\d+)(?:\.(\d))?$/', $text, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];
        $fractionDigit = isset($matches[2]) ? (int) $matches[2] : 0;

        if ($fractionDigit === 1 || $fractionDigit === 2) {
            return $whole + ($fractionDigit / 3);
        }

        if ($fractionDigit === 0) {
            return (float) $whole;
        }

        return (float) $text;
    }
}
