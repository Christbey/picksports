<?php

namespace App\Support;

/** Historical configuration pattern matching; this is not a validation artifact reader. */
class NflValidatedSignalCombos
{
    /** @return list<array<string,mixed>> */
    public function match(array $reasonCodes): array
    {
        $tokens = array_values(array_unique($reasonCodes));
        $matches = [];
        foreach ((array) config('nfl.predictions.validated_signal_combos', []) as $combo) {
            if (! is_array($combo)) {
                continue;
            }
            $required = array_values((array) ($combo['codes'] ?? []));
            if ($required === [] || array_diff($required, $tokens) !== []) {
                continue;
            }
            $matches[] = self::historicalPattern($combo);
        }
        // Match specificity, never an unsupported historical win-rate ranking.
        usort($matches, fn (array $a, array $b): int => count($b['codes']) <=> count($a['codes']));

        return $matches;
    }

    /** Sanitize legacy persisted metadata on read; never rewrite its stored receipt. */
    public static function publicAnalysis(array $analysis): array
    {
        $analysis['validated_signals'] = array_map(self::historicalPattern(...), array_slice(array_values(array_filter(
            (array) ($analysis['validated_signals'] ?? []), is_array(...)
        )), 0, 20));
        $analysis['best_validated_signal'] = is_array($analysis['best_validated_signal'] ?? null)
            ? self::historicalPattern($analysis['best_validated_signal']) : null;
        if (($analysis['bet_classification'] ?? null) === 'validated_winner_watchlist') {
            $analysis['bet_classification'] = 'no_bet_no_edge';
        }
        if (($analysis['raw_bet_classification'] ?? null) === 'validated_winner_watchlist') {
            $analysis['raw_bet_classification'] = 'no_bet_no_edge';
        }

        return $analysis;
    }

    private static function historicalPattern(array $combo): array
    {
        return [
            'name' => self::text($combo['name'] ?? null, 'historical_pattern'),
            'label' => self::text($combo['label'] ?? null, 'Historical configuration pattern'),
            'market' => self::text($combo['market'] ?? null, 'winner'),
            'tier' => 'unverified',
            'validation_status' => 'historical_unverified',
            'evidence_source' => 'historical_configuration',
            'sample_size' => 0,
            'winner_hit_rate' => null,
            'spread_mae' => null,
            'codes' => array_slice(array_values(array_filter((array) ($combo['codes'] ?? []), is_string(...))), 0, 32),
            'note' => 'Current validation unavailable. Historical pattern only; no betting upgrade.',
        ];
    }

    private static function text(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 128) : $fallback;
    }
}
