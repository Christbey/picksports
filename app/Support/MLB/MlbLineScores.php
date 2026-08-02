<?php

namespace App\Support\MLB;

final class MlbLineScores
{
    /**
     * @return list<mixed>
     */
    public static function normalize(mixed $lineScores): array
    {
        $decoded = $lineScores;

        for ($attempt = 0; $attempt < 2 && is_string($decoded); $attempt++) {
            $next = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $decoded = $next;
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            static function (mixed $score): mixed {
                if (is_array($score)) {
                    return $score['displayValue'] ?? $score['value'] ?? null;
                }

                if (is_object($score)) {
                    return $score->displayValue ?? $score->value ?? null;
                }

                return $score;
            },
            $decoded,
        ));
    }
}
