<?php

namespace App\Support;

class NflValidatedSignalCombos
{
    /**
     * @param  list<string>  $reasonCodes
     * @return list<array<string, mixed>>
     */
    public function match(array $reasonCodes): array
    {
        $tokens = array_values(array_unique($reasonCodes));
        $matches = [];

        foreach ((array) config('nfl.predictions.validated_signal_combos', []) as $combo) {
            if (! is_array($combo)) {
                continue;
            }

            $required = array_values((array) ($combo['codes'] ?? []));
            if ($required === [] || ! $this->hasAllCodes($tokens, $required)) {
                continue;
            }

            $matches[] = [
                'name' => (string) ($combo['name'] ?? implode(' + ', $required)),
                'label' => (string) ($combo['label'] ?? str_replace('_', ' ', (string) ($combo['name'] ?? 'validated_signal'))),
                'market' => (string) ($combo['market'] ?? 'winner'),
                'tier' => (string) ($combo['tier'] ?? 'watchlist'),
                'sample_size' => (int) ($combo['sample_size'] ?? 0),
                'winner_hit_rate' => isset($combo['winner_hit_rate']) ? (float) $combo['winner_hit_rate'] : null,
                'spread_mae' => isset($combo['spread_mae']) ? (float) $combo['spread_mae'] : null,
                'codes' => $required,
                'note' => $combo['note'] ?? null,
            ];
        }

        usort($matches, function (array $a, array $b): int {
            $rank = ['premium' => 4, 'strong' => 3, 'watchlist' => 2, 'caution' => 1];
            $aRank = $rank[$a['tier']] ?? 0;
            $bRank = $rank[$b['tier']] ?? 0;

            return [
                $bRank,
                count((array) ($b['codes'] ?? [])),
                (float) ($b['winner_hit_rate'] ?? 0),
                (int) $b['sample_size'],
            ] <=> [
                $aRank,
                count((array) ($a['codes'] ?? [])),
                (float) ($a['winner_hit_rate'] ?? 0),
                (int) $a['sample_size'],
            ];
        });

        return $matches;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $required
     */
    private function hasAllCodes(array $tokens, array $required): bool
    {
        foreach ($required as $code) {
            if (! in_array((string) $code, $tokens, true)) {
                return false;
            }
        }

        return true;
    }
}
