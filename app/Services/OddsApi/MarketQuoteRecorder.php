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
     * @var array<string, string>
     */
    private const MARKET_ALIASES = [
        'h2h' => 'h2h',
        'moneyline' => 'h2h',
        'spreads' => 'spreads',
        'spread' => 'spreads',
        'run_line' => 'spreads',
        'runline' => 'spreads',
        'totals' => 'totals',
        'total' => 'totals',
    ];

    /**
     * @param  array<string, mixed>  $oddsData
     * @return Collection<int, MarketQuote>
     */
    public function record(GameOddsSnapshot $snapshot, array $oddsData): Collection
    {
        $result = $this->recordWithReport($snapshot, $oddsData);
        $quoteHashes = collect($result['rows'])->pluck('quote_hash')->all();

        if ($quoteHashes === []) {
            return new Collection;
        }

        return MarketQuote::query()
            ->whereIn('quote_hash', $quoteHashes)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $oddsData
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     quotes_normalized: int,
     *     quotes_inserted: int,
     *     quotes_existing: int,
     *     bookmakers_seen: int,
     *     markets_seen: int,
     *     outcomes_seen: int,
     *     malformed_entries: int,
     *     skipped_markets: int,
     *     skipped_outcomes: int,
     *     skipped_pairs: int,
     *     post_start_quotes: int,
     *     unknown_start_quotes: int,
     *     issues: array<int, array{reason: string, context: string}>
     * }
     */
    public function recordWithReport(GameOddsSnapshot $snapshot, array $oddsData, bool $dryRun = false): array
    {
        $result = $this->normalize($snapshot, $oddsData);
        $result['quotes_inserted'] = 0;
        $result['quotes_existing'] = 0;

        if ($result['rows'] === []) {
            return $result;
        }

        if ($dryRun) {
            $result['quotes_existing'] = MarketQuote::query()
                ->whereIn('quote_hash', collect($result['rows'])->pluck('quote_hash')->all())
                ->count();

            return $result;
        }

        $timestamp = now();
        $insertRows = collect($result['rows'])
            ->map(function (array $row) use ($timestamp): array {
                $row['metadata'] = json_encode($row['metadata'], JSON_UNESCAPED_SLASHES);
                $row['created_at'] = $timestamp;
                $row['updated_at'] = $timestamp;

                return $row;
            })
            ->all();

        $result['quotes_inserted'] = MarketQuote::query()->insertOrIgnore($insertRows);
        $result['quotes_existing'] = $result['quotes_normalized'] - $result['quotes_inserted'];

        return $result;
    }

    /**
     * @param  array<string, mixed>  $oddsData
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     quotes_normalized: int,
     *     bookmakers_seen: int,
     *     markets_seen: int,
     *     outcomes_seen: int,
     *     malformed_entries: int,
     *     skipped_markets: int,
     *     skipped_outcomes: int,
     *     skipped_pairs: int,
     *     post_start_quotes: int,
     *     unknown_start_quotes: int,
     *     issues: array<int, array{reason: string, context: string}>
     * }
     */
    public function normalize(GameOddsSnapshot $snapshot, array $oddsData): array
    {
        $result = [
            'rows' => [],
            'quotes_normalized' => 0,
            'bookmakers_seen' => 0,
            'markets_seen' => 0,
            'outcomes_seen' => 0,
            'malformed_entries' => 0,
            'skipped_markets' => 0,
            'skipped_outcomes' => 0,
            'skipped_pairs' => 0,
            'post_start_quotes' => 0,
            'unknown_start_quotes' => 0,
            'issues' => [],
        ];
        $homeTeam = $this->normalizedName($oddsData['home_team'] ?? null);
        $awayTeam = $this->normalizedName($oddsData['away_team'] ?? null);
        $bookmakers = $oddsData['bookmakers'] ?? null;

        if (! is_array($bookmakers)) {
            $this->addIssue($result, 'malformed_bookmakers', 'bookmakers must be an array');

            return $result;
        }

        foreach ($bookmakers as $bookmakerIndex => $bookmaker) {
            $result['bookmakers_seen']++;

            if (! is_array($bookmaker)) {
                $this->addIssue($result, 'malformed_bookmaker', "bookmaker index {$bookmakerIndex}");

                continue;
            }

            $markets = $bookmaker['markets'] ?? null;
            if (! is_array($markets)) {
                $this->addIssue($result, 'malformed_markets', "bookmaker index {$bookmakerIndex}");

                continue;
            }

            foreach ($markets as $marketIndex => $market) {
                $result['markets_seen']++;

                if (! is_array($market)) {
                    $result['skipped_markets']++;
                    $this->addIssue($result, 'malformed_market', "bookmaker {$bookmakerIndex}, market {$marketIndex}");

                    continue;
                }

                $rawMarketKey = Str::of((string) ($market['key'] ?? ''))->lower()->trim()->value();
                $marketKey = self::MARKET_ALIASES[$rawMarketKey] ?? null;
                $isCoreMarket = $marketKey !== null;
                $marketKey ??= $rawMarketKey !== '' ? $rawMarketKey : 'unknown';

                $outcomes = $market['outcomes'] ?? null;
                if (! is_array($outcomes)) {
                    $result['skipped_markets']++;
                    $this->addIssue($result, 'malformed_outcomes', "{$rawMarketKey} outcomes must be an array");

                    continue;
                }

                $normalizedOutcomes = [];

                foreach ($outcomes as $outcomeIndex => $outcome) {
                    $result['outcomes_seen']++;

                    if (! is_array($outcome)) {
                        $result['skipped_outcomes']++;
                        $this->addIssue($result, 'malformed_outcome', "{$rawMarketKey} outcome {$outcomeIndex}");

                        continue;
                    }

                    $normalizedOutcome = $isCoreMarket
                        ? $this->normalizeCoreOutcome(
                            $marketKey,
                            $outcome,
                            $homeTeam,
                            $awayTeam,
                        )
                        : $this->normalizeGenericOutcome(
                            $marketKey,
                            $outcome,
                            $homeTeam,
                            $awayTeam,
                        );

                    if ($normalizedOutcome === null) {
                        $result['skipped_outcomes']++;
                        $this->addIssue($result, 'invalid_outcome', "{$rawMarketKey} outcome {$outcomeIndex}");

                        continue;
                    }

                    $normalizedOutcomes[] = $normalizedOutcome;
                }

                foreach (collect($normalizedOutcomes)->groupBy('group') as $group => $pairedOutcomes) {
                    if ($isCoreMarket) {
                        $requiredSides = $marketKey === 'totals'
                            ? ['over', 'under']
                            : ['home', 'away'];
                        $sides = $pairedOutcomes->pluck('side')->all();

                        if ($pairedOutcomes->count() !== 2 || array_diff($requiredSides, $sides) !== []) {
                            $result['skipped_pairs'] += $pairedOutcomes->count();
                            $this->addIssue(
                                $result,
                                'incomplete_pair',
                                "{$rawMarketKey} group {$group}",
                                malformed: false,
                            );

                            continue;
                        }
                    }

                    $probabilitySum = (float) $pairedOutcomes->sum('implied_probability');
                    if ($isCoreMarket && $probabilitySum <= 0.0) {
                        $result['skipped_pairs'] += $pairedOutcomes->count();
                        $this->addIssue($result, 'invalid_probability_pair', "{$rawMarketKey} group {$group}");

                        continue;
                    }

                    foreach ($pairedOutcomes as $outcome) {
                        $bookmakerHomeLine = $this->bookmakerHomeLine(
                            $marketKey,
                            $outcome['side'],
                            $outcome['line'],
                        );
                        $row = $this->quoteRow(
                            $snapshot,
                            $bookmaker,
                            $rawMarketKey,
                            $marketKey,
                            $outcome,
                            $bookmakerHomeLine,
                            $outcome['implied_probability'] !== null && $probabilitySum > 0.0
                                ? $outcome['implied_probability'] / $probabilitySum
                                : null,
                        );
                        $result['rows'][] = $row;
                        $result['quotes_normalized']++;

                        if ($row['is_pregame'] === false) {
                            $result['post_start_quotes']++;
                        } elseif ($row['is_pregame'] === null) {
                            $result['unknown_start_quotes']++;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @return array{
     *     raw: array<string, mixed>,
     *     price: int,
     *     line: ?float,
     *     side: string,
     *     participant: ?string,
     *     group: string,
     *     implied_probability: float
     * }|null
     */
    private function normalizeCoreOutcome(
        string $marketKey,
        array $outcome,
        ?string $homeTeam,
        ?string $awayTeam,
    ): ?array {
        if (! is_numeric($outcome['price'] ?? null)) {
            return null;
        }

        $price = (int) $outcome['price'];
        $impliedProbability = AmericanOdds::impliedProbability($price);
        $line = is_numeric($outcome['point'] ?? null) ? (float) $outcome['point'] : null;
        $side = $this->side($outcome['name'] ?? null, $homeTeam, $awayTeam);

        if ($impliedProbability === null) {
            return null;
        }

        if (in_array($marketKey, ['spreads', 'totals'], true) && $line === null) {
            return null;
        }

        if (in_array($marketKey, ['h2h', 'spreads'], true) && ! in_array($side, ['home', 'away'], true)) {
            return null;
        }

        if ($marketKey === 'totals' && ! in_array($side, ['over', 'under'], true)) {
            return null;
        }

        $normalizedLine = $marketKey === 'h2h' ? null : $line;

        return [
            'raw' => $outcome,
            'price' => $price,
            'line' => $normalizedLine,
            'side' => $side,
            'participant' => $this->participant($outcome, $side),
            'group' => $this->noVigGroup($marketKey, null, $normalizedLine),
            'implied_probability' => $impliedProbability,
        ];
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @return array{
     *     raw: array<string, mixed>,
     *     price: ?int,
     *     line: ?float,
     *     side: string,
     *     participant: ?string,
     *     group: string,
     *     implied_probability: ?float
     * }
     */
    private function normalizeGenericOutcome(
        string $marketKey,
        array $outcome,
        ?string $homeTeam,
        ?string $awayTeam,
    ): array {
        $price = is_numeric($outcome['price'] ?? null) ? (int) $outcome['price'] : null;
        $line = is_numeric($outcome['point'] ?? null) ? (float) $outcome['point'] : null;
        $side = $this->side($outcome['name'] ?? null, $homeTeam, $awayTeam);
        $participant = $this->participant($outcome, $side);

        return [
            'raw' => $outcome,
            'price' => $price,
            'line' => $line,
            'side' => $side,
            'participant' => $participant,
            'group' => $this->noVigGroup($marketKey, $participant, $line),
            'implied_probability' => $price === null ? null : AmericanOdds::impliedProbability($price),
        ];
    }

    /**
     * @param  array<string, mixed>  $bookmaker
     * @param  array{
     *     raw: array<string, mixed>,
     *     price: ?int,
     *     line: ?float,
     *     side: string,
     *     participant: ?string,
     *     group: string,
     *     implied_probability: ?float
     * }  $outcome
     * @return array<string, mixed>
     */
    private function quoteRow(
        GameOddsSnapshot $snapshot,
        array $bookmaker,
        string $rawMarketKey,
        string $marketKey,
        array $outcome,
        ?float $bookmakerHomeLine,
        ?float $noVigProbability,
    ): array {
        $bookmakerKey = $this->nullableString($bookmaker['key'] ?? null);
        $bookmakerTitle = $this->nullableString($bookmaker['title'] ?? null);
        $isPregame = $snapshot->commence_time === null
            ? null
            : $snapshot->captured_at->lt($snapshot->commence_time);
        $identity = [
            $snapshot->getKey(),
            $bookmakerKey,
            $marketKey,
            $outcome['side'],
            $outcome['participant'],
            $outcome['line'],
            $outcome['price'],
        ];

        return [
            'game_odds_snapshot_id' => $snapshot->getKey(),
            'sport' => $snapshot->sport,
            'game_table' => $snapshot->game_table,
            'game_id' => $snapshot->game_id,
            'source' => $snapshot->source,
            'bookmaker_key' => $bookmakerKey,
            'bookmaker_title' => $bookmakerTitle,
            'market_key' => $marketKey,
            'side' => $outcome['side'],
            'participant' => $outcome['participant'],
            'line' => $outcome['line'],
            'price' => $outcome['price'],
            'bookmaker_home_line' => $bookmakerHomeLine,
            'home_margin_equivalent' => $bookmakerHomeLine === null
                ? null
                : MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine),
            'implied_probability' => $outcome['implied_probability'],
            'no_vig_probability' => $noVigProbability,
            'commence_time' => $snapshot->commence_time,
            'captured_at' => $snapshot->captured_at,
            'is_pregame' => $isPregame,
            'quote_hash' => hash(
                'sha256',
                json_encode($identity),
            ),
            'metadata' => [
                'market_type' => match ($marketKey) {
                    'h2h' => 'moneyline',
                    'spreads' => 'run_line',
                    'totals' => 'total',
                    default => $marketKey,
                },
                'raw_market_key' => $rawMarketKey,
                'spread_convention' => $bookmakerHomeLine === null
                    ? null
                    : MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                'raw_outcome' => $outcome['raw'],
            ],
        ];
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

    private function noVigGroup(string $marketKey, ?string $participant, ?float $line): string
    {
        $groupLine = $marketKey === 'spreads' && $line !== null ? abs($line) : $line;

        return implode('|', [
            $marketKey,
            $participant,
            $groupLine === null ? '' : number_format($groupLine, 3, '.', ''),
        ]);
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function addIssue(
        array &$result,
        string $reason,
        string $context,
        bool $malformed = true,
    ): void {
        if ($malformed) {
            $result['malformed_entries']++;
        }

        if (count($result['issues']) < 25) {
            $result['issues'][] = [
                'reason' => $reason,
                'context' => $context,
            ];
        }
    }
}
