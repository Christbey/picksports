<?php

namespace App\Support;

use InvalidArgumentException;

final class ScoreObservation
{
    /** Preserve the shared ESPN scalar contract; missing is not observed zero. */
    public static function from(mixed $score): ?int
    {
        if ($score === null) {
            return null;
        }
        if (is_string($score)) {
            $score = trim($score, " \t\r\n");
            if (strlen($score) > 64 || preg_match('/\A[+-]?[0-9]+(?:\.0+)?\z/D', $score) !== 1) {
                throw new InvalidArgumentException('Game scores require an observed nonnegative whole integer.');
            }
        } elseif (! is_int($score) && ! is_float($score)) {
            throw new InvalidArgumentException('Game scores require an observed nonnegative whole integer.');
        }
        $number = (float) $score;
        // All seven legacy game tables use a signed SQL INTEGER for final scores.
        if (! is_finite($number) || $number < 0 || $number > 2147483647 || floor($number) !== $number) {
            throw new InvalidArgumentException('Game scores require an observed nonnegative whole SQL integer.');
        }

        return (int) $number;
    }

    /** Read-only consumers retain malformed historic observations as unavailable. */
    public static function known(mixed $score): ?int
    {
        try {
            return self::from($score);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
