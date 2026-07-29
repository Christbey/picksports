<?php

namespace App\Services\OddsApi;

use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Support\Odds\AmericanOdds;
use App\Support\Odds\MarketSpread;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class MarketQuoteRecorder
{
    /**
     * @param  array<string, mixed>  $oddsData
     * @return Collection<int, MarketQuote>
     */
    public function record(GameOddsSnapshot $snapshot, array $oddsData): Collection
    {
        $rows = collect();
        $homeTeam = $this->normalizedName($oddsData['home_team'] ?? null);
        $awayTeam = $this->normalizedName($oddsData['away_team'] ?? null);

        foreach ((array) ($oddsData['bookmakers'] ?? []) as $bookmaker) {
            if (! is_array($bookmaker)) {
                continue;
            }

            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (! is_array($market)) {
                    continue;
                }

                $marketKey = (string) ($market['key'] ?? 'unknown');
                $outcomes = collect((array) ($market['outcomes'] ?? []))
                    ->filter(fn (mixed $outcome): bool => is_array($outcome))
                    ->map(function (array $outcome) use ($homeTeam, $awayTeam, $marketKey): array {
                        $price = is_numeric($outcome['price'] ?? null) ? (int) $outcome['price'] : null;
                        $line = is_numeric($outcome['point'] ?? null) ? (float) $outcome['point'] : null;
                        $side = $this->side($outcome['name'] ?? null, $homeTeam, $awayTeam);
                        $participant = $this->participant($outcome, $side);
                        $group = $this->noVigGroup($marketKey, $outcome, $line);

                        return [
                            'outcome' => $outcome,
                            'price' => $price,
                            'line' => $line,
                            'side' => $side,
                            'participant' => $participant,
                            'group' => $group,
                            'implied_probability' => $price === null ? null : AmericanOdds::impliedProbability($price),
                        ];
                    });

                $probabilitySums = $outcomes
                    ->groupBy('group')
                    ->map(fn ($group): float => (float) $group->sum('implied_probability'));

                foreach ($outcomes as $outcome) {
                    $bookmakerHomeLine = $this->bookmakerHomeLine($marketKey, $outcome['side'], $outcome['line']);
                    $sum = (float) ($probabilitySums[$outcome['group']] ?? 0.0);
                    $impliedProbability = $outcome['implied_probability'];
                    $noVigProbability = $impliedProbability !== null && $sum > 0.0
                        ? $impliedProbability / $sum
                        : null;
                    $identity = [
                        $snapshot->id,
                        $bookmaker['key'] ?? null,
                        $marketKey,
                        $outcome['side'],
                        $outcome['participant'],
                        $outcome['line'],
                        $outcome['price'],
                    ];

                    $quoteHash = hash('sha256', json_encode($identity));
                    $rows->push(MarketQuote::query()->firstOrCreate([
                        'quote_hash' => $quoteHash,
                    ], [
                        'game_odds_snapshot_id' => $snapshot->id,
                        'sport' => $snapshot->sport,
                        'game_table' => $snapshot->game_table,
                        'game_id' => $snapshot->game_id,
                        'source' => $snapshot->source,
                        'bookmaker_key' => $bookmaker['key'] ?? null,
                        'bookmaker_title' => $bookmaker['title'] ?? null,
                        'market_key' => $marketKey,
                        'side' => $outcome['side'],
                        'participant' => $outcome['participant'],
                        'line' => $outcome['line'],
                        'price' => $outcome['price'],
                        'bookmaker_home_line' => $bookmakerHomeLine,
                        'home_margin_equivalent' => $bookmakerHomeLine === null
                            ? null
                            : MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine),
                        'implied_probability' => $impliedProbability,
                        'no_vig_probability' => $noVigProbability,
                        'commence_time' => $snapshot->commence_time,
                        'captured_at' => $snapshot->captured_at,
                        'is_pregame' => $snapshot->commence_time === null
                            ? null
                            : $snapshot->captured_at->lt($snapshot->commence_time),
                        'metadata' => [
                            'spread_convention' => $bookmakerHomeLine === null
                                ? null
                                : MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                            'raw_outcome' => $outcome['outcome'],
                        ],
                    ]));
                }
            }
        }

        return new Collection($rows->all());
    }

    private function side(mixed $name, ?string $homeTeam, ?string $awayTeam): string
    {
        $normalized = $this->normalizedName($name);

        return match (true) {
            $normalized !== null && $normalized === $homeTeam => 'home',
            $normalized !== null && $normalized === $awayTeam => 'away',
            in_array($normalized, ['over', 'under', 'yes', 'no'], true) => $normalized,
            default => Str::of((string) $name)->lower()->slug('_')->value() ?: 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function participant(array $outcome, string $side): ?string
    {
        $description = trim((string) ($outcome['description'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        return in_array($side, ['home', 'away'], true)
            ? trim((string) ($outcome['name'] ?? '')) ?: null
            : null;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function noVigGroup(string $marketKey, array $outcome, ?float $line): string
    {
        $participant = in_array($marketKey, ['h2h', 'spreads', 'totals'], true)
            ? ''
            : (string) ($outcome['description'] ?? '');
        $groupLine = $marketKey === 'spreads' && $line !== null ? abs($line) : $line;

        return implode('|', [$marketKey, $participant, $groupLine]);
    }

    private function bookmakerHomeLine(string $marketKey, string $side, ?float $line): ?float
    {
        if ($marketKey !== 'spreads' || $line === null) {
            return null;
        }

        return match ($side) {
            'home' => $line,
            'away' => -1 * $line,
            default => null,
        };
    }

    private function normalizedName(mixed $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return Str::of($name)->lower()->squish()->value();
    }
}
