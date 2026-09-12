<?php

namespace App\Services\CFB\Live;

use Carbon\CarbonImmutable;

class LiveMarketComparison
{
    public function fresh(?string $updatedAt, string $commenceTime): bool
    {
        if (! $updatedAt) {
            return false;
        }
        try {
            $updated = CarbonImmutable::parse($updatedAt);

            return $updated->gte(CarbonImmutable::parse($commenceTime))
                && $updated->betweenIncluded(now()->subMinutes(3), now()->addSeconds(30));
        } catch (\Throwable) {
            return false;
        }
    }

    public function probability(int|float $price): ?float
    {
        return $price === 0 ? null : ($price < 0 ? abs($price) / (abs($price) + 100) : 100 / ($price + 100));
    }

    public function games(array $response, ?array $projection): array
    {
        $result = [];
        foreach ($response['bookmakers'] ?? [] as $book) {
            foreach ($book['markets'] ?? [] as $market) {
                if (! in_array($market['key'] ?? '', ['h2h', 'spreads', 'totals'], true)) {
                    continue;
                }
                $fresh = $this->fresh($market['last_update'] ?? $book['last_update'] ?? null, $response['commence_time']);
                $outcomes = collect($market['outcomes'] ?? []);
                $home = $outcomes->firstWhere('name', $response['home_team']);
                $away = $outcomes->firstWhere('name', $response['away_team']);
                $over = $outcomes->firstWhere('name', 'Over');
                $under = $outcomes->firstWhere('name', 'Under');
                $row = ['bookmaker' => $book['key'], 'market' => $market['key'], 'updated_at' => $market['last_update'] ?? $book['last_update'] ?? null,
                    'fresh' => $fresh, 'outcomes' => $outcomes->values()->all(), 'difference' => null, 'lean' => null];
                if ($fresh && $projection) {
                    if ($market['key'] === 'spreads' && is_numeric($home['point'] ?? null) && is_numeric($away['point'] ?? null)
                        && abs((float) $home['point'] + (float) $away['point']) < .001) {
                        // Projections are home-minus-away margins; sportsbook home handicaps have the opposite sign.
                        $row['home_line'] = (float) $home['point'];
                        $row['difference'] = round($projection['spread'] + (float) $home['point'], 1);
                        $row['lean'] = $row['difference'] > 0 ? 'Home' : ($row['difference'] < 0 ? 'Away' : null);
                    } elseif ($market['key'] === 'totals' && is_numeric($over['point'] ?? null) && ($over['point'] ?? null) === ($under['point'] ?? null)) {
                        $row['difference'] = round($projection['total'] - (float) $over['point'], 1);
                        $row['lean'] = $row['difference'] > 0 ? 'Over' : ($row['difference'] < 0 ? 'Under' : null);
                    } elseif ($market['key'] === 'h2h' && is_numeric($home['price'] ?? null) && is_numeric($away['price'] ?? null)) {
                        $h = $this->probability($home['price']);
                        $a = $this->probability($away['price']);
                        if ($h && $a) {
                            $row['market_home_probability'] = round($h / ($h + $a), 4);
                            $row['difference'] = round(100 * ($projection['home_win_probability'] - $h / ($h + $a)), 1);
                            $row['lean'] = $row['difference'] > 0 ? 'Home' : ($row['difference'] < 0 ? 'Away' : null);
                        }
                    }
                }
                $result[] = $row;
            }
        }

        return $result;
    }
}
