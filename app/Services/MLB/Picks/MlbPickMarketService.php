<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Support\Odds\AmericanOdds;

class MlbPickMarketService
{
    /**
     * @return array{home:?array<string,mixed>,away:?array<string,mixed>}
     */
    public function sideOutcomes(Game $game, array $keys): array
    {
        $outcomes = ['home' => null, 'away' => null];
        $market = $this->firstMarket($game, $keys);
        if (! $market) {
            return $outcomes;
        }

        foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
            if (! is_array($outcome) || ! is_numeric($outcome['price'] ?? null)) {
                continue;
            }

            if ($this->teamMatchesOutcome($game->homeTeam, (string) ($outcome['name'] ?? ''))) {
                $outcomes['home'] = $this->normalizeOutcome($outcome, $market);
            }

            if ($this->teamMatchesOutcome($game->awayTeam, (string) ($outcome['name'] ?? ''))) {
                $outcomes['away'] = $this->normalizeOutcome($outcome, $market);
            }
        }

        return $outcomes;
    }

    /**
     * @return array{over:?array<string,mixed>,under:?array<string,mixed>}
     */
    public function totalOutcomes(Game $game, array $keys): array
    {
        $outcomes = ['over' => null, 'under' => null];
        $market = $this->firstMarket($game, $keys);
        if (! $market) {
            return $outcomes;
        }

        foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
            if (! is_array($outcome) || ! is_numeric($outcome['price'] ?? null)) {
                continue;
            }

            $side = strtolower((string) ($outcome['name'] ?? ''));
            if (in_array($side, ['over', 'under'], true)) {
                $outcomes[$side] = $this->normalizeOutcome($outcome, $market);
            }
        }

        return $outcomes;
    }

    /**
     * @param  array{home:?array<string,mixed>,away:?array<string,mixed>}  $outcomes
     * @return array{home:?float,away:?float}
     */
    public function noVigSides(array $outcomes): array
    {
        return AmericanOdds::noVigProbabilities(
            isset($outcomes['home']['price']) ? (int) $outcomes['home']['price'] : null,
            isset($outcomes['away']['price']) ? (int) $outcomes['away']['price'] : null,
        );
    }

    /**
     * @param  array{over:?array<string,mixed>,under:?array<string,mixed>}  $outcomes
     * @return array{over:?float,under:?float}
     */
    public function noVigTotals(array $outcomes): array
    {
        $over = isset($outcomes['over']['price']) ? (int) $outcomes['over']['price'] : null;
        $under = isset($outcomes['under']['price']) ? (int) $outcomes['under']['price'] : null;
        $overProbability = $over !== null ? AmericanOdds::impliedProbability($over) : null;
        $underProbability = $under !== null ? AmericanOdds::impliedProbability($under) : null;
        $sum = ($overProbability ?? 0.0) + ($underProbability ?? 0.0);

        if ($overProbability === null || $underProbability === null || $sum <= 0.0) {
            return ['over' => null, 'under' => null];
        }

        return ['over' => $overProbability / $sum, 'under' => $underProbability / $sum];
    }

    public function implied(?int $price): ?float
    {
        return $price !== null ? AmericanOdds::impliedProbability($price) : null;
    }

    public function stale(Game $game): bool
    {
        return $game->odds_updated_at !== null
            && $game->odds_updated_at->lt(now()->subHours((int) config('mlb.signals.odds_stale_hours', 12)));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,mixed>|null
     */
    private function firstMarket(Game $game, array $keys): ?array
    {
        foreach ((array) data_get($game->odds_data, 'bookmakers', []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (is_array($market) && in_array((string) ($market['key'] ?? ''), $keys, true)) {
                    $market['book'] = $bookmaker['title'] ?? $bookmaker['key'] ?? null;

                    return $market;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $outcome
     * @param  array<string,mixed>  $market
     * @return array<string,mixed>
     */
    private function normalizeOutcome(array $outcome, array $market): array
    {
        return [
            'name' => $outcome['name'] ?? null,
            'line' => is_numeric($outcome['point'] ?? null) ? (float) $outcome['point'] : null,
            'price' => (int) $outcome['price'],
            'book' => $market['book'] ?? null,
            'market_key' => $market['key'] ?? null,
        ];
    }

    private function teamMatchesOutcome(?Team $team, string $outcomeName): bool
    {
        if (! $team) {
            return false;
        }

        $normalized = $this->normalize($outcomeName);
        foreach ([
            (string) ($team->display_name ?? ''),
            trim(((string) ($team->location ?? '')).' '.((string) ($team->name ?? ''))),
            (string) ($team->name ?? ''),
            (string) ($team->abbreviation ?? ''),
        ] as $candidate) {
            $candidate = $this->normalize($candidate);
            if ($candidate !== '' && ($candidate === $normalized || str_contains($candidate, $normalized) || str_contains($normalized, $candidate))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }
}
