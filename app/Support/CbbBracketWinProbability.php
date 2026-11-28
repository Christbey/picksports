<?php

namespace App\Support;

final class CbbBracketWinProbability
{
    /** @return array{homeWinProbability:float,awayWinProbability:float}|null */
    public static function from(mixed $value): ?array
    {
        if (is_string($value)) {
            if (strlen($value) > 64 || preg_match('/^(?:0(?:\.[0-9]+)?|1(?:\.0+)?)$/D', $value) !== 1) {
                return null;
            }
        } elseif (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $probability = (float) $value;
        if (! is_finite($probability) || $probability < 0 || $probability > 1) {
            return null;
        }

        return ['homeWinProbability' => $probability, 'awayWinProbability' => round(1 - $probability, 3)];
    }
}
