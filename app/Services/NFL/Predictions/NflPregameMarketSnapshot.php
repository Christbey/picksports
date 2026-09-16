<?php

namespace App\Services\NFL\Predictions;

use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\NFL\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class NflPregameMarketSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function capture(Game $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        $maximumQuoteAgeMinutes = $this->maximumQuoteAgeMinutes();
        $freshAfter = $capturedAt->subMinutes($maximumQuoteAgeMinutes);
        $snapshot = GameOddsSnapshot::query()
            ->with(['marketQuotes' => fn ($query) => $query
                ->whereIn('market_key', ['h2h', 'spreads', 'totals'])
                ->where('is_pregame', true)
                ->where('captured_at', '>=', $freshAfter)
                ->where('captured_at', '<=', $capturedAt)
                ->where('captured_at', '<', $cutoffAt)
                ->orderBy('market_key')
                ->orderBy('bookmaker_key')
                ->orderBy('side')
                ->orderBy('id')])
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())
            ->where('captured_at', '>=', $freshAfter)
            ->where('captured_at', '<=', $capturedAt)
            ->where('captured_at', '<', $cutoffAt)
            ->whereHas('marketQuotes', fn ($query) => $query
                ->whereIn('market_key', ['h2h', 'spreads', 'totals'])
                ->where('is_pregame', true)
                ->where('captured_at', '>=', $freshAfter)
                ->where('captured_at', '<=', $capturedAt)
                ->where('captured_at', '<', $cutoffAt))
            ->latest('captured_at')
            ->latest('id')
            ->first();
        $marketQuotes = $snapshot?->marketQuotes
            ->filter(function (MarketQuote $quote) use ($freshAfter, $capturedAt, $cutoffAt): bool {
                $observedAt = $this->quoteObservedAt($quote);

                return $observedAt->betweenIncluded($freshAfter, $capturedAt)
                    && $observedAt->lt($cutoffAt);
            })
            ->values() ?? collect();

        if ($snapshot === null || $marketQuotes->isEmpty()) {
            return [
                'available' => false,
                'source' => 'market_quotes',
                'reason' => 'pregame_market_quote_missing_or_stale',
                'captured_at' => null,
                'game_odds_snapshot_id' => null,
                'maximum_quote_age_minutes' => $maximumQuoteAgeMinutes,
                'quotes' => [],
                'consensus' => [],
                'market_coverage' => [
                    'spread' => false,
                    'total' => false,
                    'missing' => ['spreads', 'totals'],
                ],
            ];
        }

        $quotes = $marketQuotes
            ->map(fn (MarketQuote $quote): array => $this->quotePayload($quote))
            ->values();
        $consensus = [
            'moneyline' => $this->consensus($marketQuotes, 'h2h', 'home'),
            'spread' => $this->consensus($marketQuotes, 'spreads', 'home'),
            'total' => $this->consensus($marketQuotes, 'totals', 'over'),
        ];
        $spreadCovered = $this->completeTwoSidedMarket($consensus['spread']);
        $totalCovered = $this->completeTwoSidedMarket($consensus['total']);
        $missingMarkets = array_values(array_filter([
            $spreadCovered ? null : 'spreads',
            $totalCovered ? null : 'totals',
        ]));

        return [
            'available' => $missingMarkets === [],
            'source' => 'market_quotes',
            'reason' => $missingMarkets === [] ? null : 'required_pregame_market_coverage_missing',
            'game_odds_snapshot_id' => $snapshot->getKey(),
            'payload_hash' => $snapshot->payload_hash,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'commence_time' => $snapshot->commence_time?->toIso8601String(),
            'maximum_quote_age_minutes' => $maximumQuoteAgeMinutes,
            'quotes' => $quotes->all(),
            'consensus' => $consensus,
            'market_coverage' => [
                'spread' => $spreadCovered,
                'total' => $totalCovered,
                'missing' => $missingMarkets,
            ],
        ];
    }

    /** @param array<string, mixed>|null $market */
    private function completeTwoSidedMarket(?array $market): bool
    {
        return is_numeric($market['line'] ?? null)
            && is_numeric($market['price'] ?? null)
            && is_numeric(data_get($market, 'opposite_quote.line'))
            && is_numeric(data_get($market, 'opposite_quote.price'));
    }

    private function maximumQuoteAgeMinutes(): int
    {
        return max(1, (int) config('nfl.predictions.pregame_market.maximum_quote_age_minutes', 60));
    }

    /**
     * @param  Collection<int, MarketQuote>  $quotes
     * @return array<string, mixed>|null
     */
    private function consensus(Collection $quotes, string $marketKey, string $side): ?array
    {
        $candidates = $quotes
            ->where('market_key', $marketKey)
            ->where('side', $side)
            ->filter(fn (MarketQuote $quote): bool => $marketKey === 'h2h'
                ? is_numeric($quote->no_vig_probability)
                : is_numeric($quote->line))
            ->sortBy(fn (MarketQuote $quote): float => $marketKey === 'h2h'
                ? (float) $quote->no_vig_probability
                : (float) $quote->line)
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $selected = $candidates->get(intdiv($candidates->count(), 2));
        if (! $selected instanceof MarketQuote) {
            return null;
        }

        $payload = $this->quotePayload($selected);
        $oppositeSide = match ($side) {
            'home' => 'away',
            'over' => 'under',
            default => null,
        };
        $opposite = $oppositeSide === null ? null : $quotes
            ->where('market_key', $marketKey)
            ->where('side', $oppositeSide)
            ->first(fn (MarketQuote $quote): bool => $quote->game_odds_snapshot_id === $selected->game_odds_snapshot_id
                && $quote->bookmaker_key === $selected->bookmaker_key
                && $quote->bookmaker_title === $selected->bookmaker_title
                && $this->pairedLinesMatch($marketKey, $selected, $quote));

        return [
            ...$payload,
            'bookmaker_count' => $candidates
                ->map(fn (MarketQuote $quote): string => (string) ($quote->bookmaker_key
                    ?? $quote->bookmaker_title
                    ?? "unknown:{$quote->id}"))
                ->unique()
                ->count(),
            'opposite_quote' => $opposite instanceof MarketQuote
                ? $this->quotePayload($opposite)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function quotePayload(MarketQuote $quote): array
    {
        return [
            'market_quote_id' => $quote->getKey(),
            'quote_hash' => $quote->quote_hash,
            'market_key' => $quote->market_key,
            'side' => $quote->side,
            'line' => is_numeric($quote->line) ? (float) $quote->line : null,
            'price' => is_numeric($quote->price) ? (int) $quote->price : null,
            'bookmaker_key' => $quote->bookmaker_key,
            'bookmaker_title' => $quote->bookmaker_title,
            'implied_probability' => is_numeric($quote->implied_probability)
                ? (float) $quote->implied_probability
                : null,
            'no_vig_probability' => is_numeric($quote->no_vig_probability)
                ? (float) $quote->no_vig_probability
                : null,
            'captured_at' => $quote->captured_at?->toIso8601String(),
            'provider_observed_at' => data_get($quote->metadata, 'provider_observed_at'),
            'freshness_observed_at' => $this->quoteObservedAt($quote)->toIso8601String(),
            'is_pregame' => (bool) $quote->is_pregame,
        ];
    }

    private function quoteObservedAt(MarketQuote $quote): CarbonImmutable
    {
        $providerObservedAt = data_get($quote->metadata, 'provider_observed_at');
        if (is_string($providerObservedAt) && $providerObservedAt !== '') {
            try {
                return CarbonImmutable::parse($providerObservedAt);
            } catch (Throwable) {
                // Fall through to the persisted ingestion timestamp.
            }
        }

        return CarbonImmutable::instance($quote->captured_at);
    }

    private function pairedLinesMatch(string $marketKey, MarketQuote $selected, MarketQuote $opposite): bool
    {
        if ($marketKey === 'h2h') {
            return true;
        }

        if (! is_numeric($selected->line) || ! is_numeric($opposite->line)) {
            return false;
        }

        return $marketKey === 'spreads'
            ? abs((float) $selected->line + (float) $opposite->line) <= 0.001
            : abs((float) $selected->line - (float) $opposite->line) <= 0.001;
    }
}
