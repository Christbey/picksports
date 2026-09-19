<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;

/** Executable quotes are confirmed independently from the historical consensus/CLV series. */
class CfbSpreadMarketConfirmation
{
    public function assess(Game $game, string $side, float $modelHomeMargin): array
    {
        $now = CarbonImmutable::now();
        $age = max(1, (int) config('cfb.predictions.spread_value.maximum_quote_age_minutes', 60));
        $minimumBooks = max(2, (int) config('cfb.predictions.spread_value.minimum_books', 2));
        $maximumRange = (float) config('cfb.predictions.spread_value.maximum_book_line_range', 2.5);
        $minimumEdge = (float) config('cfb.predictions.spread_value.minimum_edge_points', 3);
        $start = $game->sportEvent?->starts_at?->toImmutable();
        if (! $start && $game->game_date && $game->game_time) {
            $start = CarbonImmutable::parse($game->game_date->format('Y-m-d').' '.$game->game_time, config('app.timezone', 'UTC'));
        }
        $flags = [];
        if (! $start || ! $now->lt($start)) {
            $flags[] = 'market_not_confirmed_pregame';
        }
        $quotes = MarketQuote::query()->where('sport', 'cfb')->where('game_table', $game->getTable())
            ->where('game_id', $game->id)->where('market_key', 'spreads')->where('captured_at', '<=', $now)
            ->where('created_at', '<=', $now)
            ->whereIn('side', ['home', 'away'])->orderByDesc('captured_at')->orderByDesc('id')->get();
        $selected = $quotes->where('side', $side)->unique(fn ($quote) => strtolower(trim((string) $quote->bookmaker_key)));
        $accepted = [];
        $rejected = [];
        foreach ($selected as $quote) {
            $reasons = [];
            $book = strtolower(trim((string) $quote->bookmaker_key));
            $observed = $this->timestamp(data_get($quote->metadata, 'provider_observed_at'));
            $captured = $quote->captured_at?->toImmutable();
            if ($book === '') {
                $reasons[] = 'missing_bookmaker';
            } elseif (! in_array($book, (array) config('cfb.odds.bookmakers', ['draftkings', 'fanduel', 'betmgm']), true)) {
                $reasons[] = 'unrecognized_bookmaker';
            }
            if (! $observed) {
                $reasons[] = 'missing_provider_quote_timestamp';
            }
            if (! $captured || ! $captured->betweenIncluded($now->subMinutes($age), $now)
                || ($observed && (! $observed->betweenIncluded($now->subMinutes($age), $now) || $observed->gt($captured)))) {
                $reasons[] = 'stale_or_future_market_quote';
            }
            if (! $quote->is_pregame || ! $start || ! $captured || ! $captured->lt($start)
                || ! $quote->commence_time || ! $now->lt($quote->commence_time)) {
                $reasons[] = 'quote_not_confirmed_pregame';
            }
            if ($start && $quote->commence_time && abs($quote->commence_time->diffInMinutes($start, false)) > 15) {
                $reasons[] = 'market_start_time_mismatch';
            }
            $line = is_numeric($quote->line) ? (float) $quote->line : null;
            $price = is_numeric($quote->price) ? (int) $quote->price : null;
            if ($line === null || $price === null || abs($price) < 100
                || $price < (int) config('cfb.predictions.spread_value.minimum_american_price', -125)
                || $price > (int) config('cfb.predictions.spread_value.maximum_american_price', 200)) {
                $reasons[] = 'unacceptable_spread_price';
            }
            $opposite = $quotes->first(fn ($other) => $other->game_odds_snapshot_id === $quote->game_odds_snapshot_id
                && $other->bookmaker_key === $quote->bookmaker_key && $other->side !== $side
                && is_numeric($other->line) && $line !== null && abs((float) $other->line + $line) < .001
                && is_numeric($other->price) && abs((float) $other->price) >= 100 && $other->is_pregame);
            $oppositeObserved = $opposite ? $this->timestamp(data_get($opposite->metadata, 'provider_observed_at')) : null;
            if (! $opposite || ! $oppositeObserved || ! $opposite->captured_at
                || ! $oppositeObserved->betweenIncluded($now->subMinutes($age), $now)
                || $oppositeObserved->gt($opposite->captured_at)
                || ! $opposite->captured_at->betweenIncluded($now->subMinutes($age), $now)
                || ! $start || ! $opposite->captured_at->lt($start)) {
                $reasons[] = 'missing_fresh_paired_spread_quote';
            }
            $edge = $line === null ? null : round(($side === 'home' ? $modelHomeMargin : -$modelHomeMargin) + $line, 3);
            $entry = ['quote_id' => $quote->id, 'snapshot_id' => $quote->game_odds_snapshot_id,
                'bookmaker' => $book, 'side' => $side, 'line' => $line, 'price' => $price,
                'edge_points' => $edge, 'captured_at' => $captured?->toIso8601String(),
                'provider_observed_at' => $observed?->toIso8601String(),
                'implied_probability' => is_numeric($quote->implied_probability) ? (float) $quote->implied_probability : null,
                'no_vig_probability' => is_numeric($quote->no_vig_probability) ? (float) $quote->no_vig_probability : null];
            if ($reasons !== []) {
                $rejected[] = [...$entry, 'risk_flags' => $reasons];
            } else {
                $accepted[] = $entry;
            }
        }
        $lines = collect($accepted)->pluck('line');
        $median = $lines->isNotEmpty() ? (float) $lines->median() : null;
        $range = $lines->isNotEmpty() ? round($lines->max() - $lines->min(), 3) : null;
        if ($range !== null && $range > $maximumRange) {
            $flags[] = 'wide_bookmaker_line_range';
        }
        $confirmed = array_values(array_filter($accepted, fn ($quote) => $quote['edge_points'] >= $minimumEdge
            && ($median === null || abs($quote['line'] - $median) <= $maximumRange)));
        if (count($accepted) < $minimumBooks) {
            $flags[] = 'insufficient_fresh_priced_books';
        }
        if (count($confirmed) < $minimumBooks) {
            $flags[] = 'insufficient_same_side_book_confirmation';
        }
        usort($confirmed, fn ($a, $b) => ($b['line'] <=> $a['line']) ?: ($b['price'] <=> $a['price']) ?: strcmp($a['bookmaker'], $b['bookmaker']));

        return ['supported' => $flags === [], 'side' => $side, 'minimum_books' => $minimumBooks,
            'fresh_book_count' => count($accepted), 'confirming_book_count' => count($confirmed),
            'maximum_quote_age_minutes' => $age, 'median_side_line' => $median, 'line_range' => $range,
            'selection_policy' => 'best handicap among confirming quotes within price limits; highest price breaks line ties',
            'best_quote' => $confirmed[0] ?? null, 'confirmed_quotes' => $confirmed,
            'fresh_quotes' => $accepted, 'rejected_quotes' => $rejected, 'risk_flags' => $flags];
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
