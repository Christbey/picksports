<?php

namespace App\Services\Sports\Elo;

use DomainException;

/** Exact decimal text at storage precision; no binary-float equality or lossy rounding. */
class EloFixedScale
{
    public function rating(mixed $value): string
    {
        return $this->decimal($value, 4, 8);
    }

    public function factor(mixed $value): string
    {
        $result = $this->decimal($value, 7, 1);
        if (str_starts_with($result, '-') || (str_starts_with($result, '1.') && $result !== '1.0000000') || ! in_array($result[0], ['0', '1'], true)) {
            throw new DomainException('Elo factor must be between zero and one.');
        }

        return $result;
    }

    private function decimal(mixed $value, int $scale, int $integerDigits): string
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || (is_float($value) && ! is_finite($value))) {
            throw new DomainException('Elo decimal observation is unavailable or invalid.');
        }
        $text = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : (string) $value;
        if (strlen($text) > 128 || preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,2}))?\z/', $text, $parts) !== 1) {
            throw new DomainException('Elo decimal must be explicit numeric text.');
        }
        $fraction = $parts[3] ?? '';
        $exponent = (int) ($parts[4] ?? 0);
        if (abs($exponent) > 64) {
            throw new DomainException('Elo decimal exponent exceeds the budget.');
        }
        $digits = $parts[2].$fraction;
        $point = strlen($parts[2]) + $exponent;
        if ($point < 0) {
            $digits = str_repeat('0', -$point).$digits;
            $point = 0;
        }
        if ($point > strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        }
        $whole = ltrim(substr($digits, 0, $point), '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim(substr($digits, $point), '0');
        if (strlen($whole) > $integerDigits || strlen($fraction) > $scale) {
            throw new DomainException('Elo decimal exceeds exact storage precision.');
        }
        $zero = $whole === '0' && $fraction === '';

        return ($parts[1] === '-' && ! $zero ? '-' : '').$whole.'.'.str_pad($fraction, $scale, '0');
    }
}
