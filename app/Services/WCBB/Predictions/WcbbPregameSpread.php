<?php

namespace App\Services\WCBB\Predictions;

use App\Models\MarketQuote;
use App\Models\WCBB\Game;
use Carbon\CarbonImmutable;

class WcbbPregameSpread
{
    public function capture(Game $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        $freshAfter = $capturedAt->subHours(36);
        $quotes = MarketQuote::query()
            ->where('sport', 'wcbb')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())
            ->where('market_key', 'spreads')
            ->whereIn('side', ['home', 'away'])
            ->where('is_pregame', true)
            ->whereNotNull('bookmaker_key')
            ->whereNotNull('line')
            ->whereRaw('ABS(price) >= 100')
            ->whereBetween('captured_at', [$freshAfter, $capturedAt])
            ->where('captured_at', '<', $cutoffAt)
            ->latest('captured_at')
            ->latest('id')
            ->get()
            ->filter(function (MarketQuote $quote) use ($freshAfter, $capturedAt, $cutoffAt): bool {
                $observedAt = $this->observedAt($quote);

                return $observedAt !== null && $observedAt->betweenIncluded($freshAfter, $capturedAt)
                    && $observedAt->lte($quote->captured_at) && $observedAt->lt($cutoffAt);
            });

        foreach ($quotes->groupBy('game_odds_snapshot_id') as $snapshotQuotes) {
            $pairs = $snapshotQuotes->groupBy('bookmaker_key')
                ->map(function ($book): ?array {
                    if ($book->count() !== 2) {
                        return null;
                    }
                    $home = $book->firstWhere('side', 'home');
                    $away = $book->firstWhere('side', 'away');

                    return $home && $away && abs((float) $home->line + (float) $away->line) <= 0.001
                        ? [$home, $away]
                        : null;
                })->filter()->sortBy(fn (array $pair): float => (float) $pair[0]->line)->values();
            if ($pairs->isEmpty()) {
                continue;
            }

            [$home, $away] = $pairs->get(intdiv($pairs->count(), 2));

            return [
                'available' => true,
                'source' => 'market_quotes',
                'game_odds_snapshot_id' => $home->game_odds_snapshot_id,
                'captured_at' => $snapshotQuotes->first()->captured_at->toIso8601String(),
                'consensus' => ['spread' => $this->payload($home) + ['opposite_quote' => $this->payload($away)]],
            ];
        }

        return ['available' => false, 'source' => 'market_quotes', 'reason' => 'fresh_paired_spread_unavailable'];
    }

    private function observedAt(MarketQuote $quote): ?CarbonImmutable
    {
        $observedAt = data_get($quote->metadata, 'provider_observed_at');
        try {
            return CarbonImmutable::parse($observedAt ?? $quote->captured_at);
        } catch (\Exception) {
            return null;
        }
    }

    private function payload(MarketQuote $quote): array
    {
        return [
            'market_quote_id' => $quote->id,
            'quote_hash' => $quote->quote_hash,
            'side' => $quote->side,
            'bookmaker_key' => $quote->bookmaker_key,
            'line' => (float) $quote->line,
            'price' => (int) $quote->price,
            'captured_at' => $quote->captured_at->toIso8601String(),
            'observed_at' => $this->observedAt($quote)?->toIso8601String(),
        ];
    }
}
