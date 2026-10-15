<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;

/** Freeze paired, same-book contracts observed and stored by capture; never use closing quotes from later. */
class CfbFrozenMarketEvidence
{
    public const VERSION = 'cfb-frozen-market-v1';

    public function capture(Game $game, CarbonImmutable $asOf, CarbonImmutable $kickoff): array
    {
        $asOf = $asOf->setTimezone(config('app.timezone'));
        $kickoff = $kickoff->setTimezone(config('app.timezone'));
        $result = ['version' => self::VERSION, 'captured_at' => $asOf->toIso8601String(),
            'spread' => null, 'total' => null, 'available' => false, 'missing' => []];
        if ($asOf->gte($kickoff)) {
            return [...$result, 'missing' => ['capture_not_pregame']];
        }
        foreach (['spread' => ['spreads', 'home', 'away'], 'total' => ['totals', 'over', 'under']] as $key => [$market, $side, $opposite]) {
            $quotes = MarketQuote::query()->where('sport', 'cfb')->where('game_table', 'cfb_games')->where('game_id', $game->id)
                ->where('market_key', $market)->where('side', $side)->where('is_pregame', true)
                ->where('captured_at', '<=', $asOf)->where('captured_at', '>=', $asOf->subHours(36))
                ->where('created_at', '<=', $asOf)->where('captured_at', '<', $kickoff)->where('created_at', '<', $kickoff)
                ->orderByDesc('captured_at')->orderByDesc('id')->cursor();
            foreach ($quotes as $quote) {
                if (! $this->safe($quote, $asOf, $kickoff) || ! $this->participantMatches($game, $quote, $key === 'spread' ? 'home' : null)) {
                    continue;
                }
                $other = MarketQuote::query()->where('sport', 'cfb')->where('game_table', 'cfb_games')->where('game_id', $game->id)
                    ->where('game_odds_snapshot_id', $quote->game_odds_snapshot_id)->where('bookmaker_key', $quote->bookmaker_key)
                    ->where('market_key', $market)->where('side', $opposite)->where('is_pregame', true)
                    ->orderByDesc('id')->get()->first(fn ($q) => $this->safe($q, $asOf, $kickoff)
                        && $this->participantMatches($game, $q, $key === 'spread' ? 'away' : null)
                        && CfbStoredPregameQuote::contract($q) === CfbStoredPregameQuote::contract($quote)
                        && abs((float) $q->line - ($key === 'spread' ? -(float) $quote->line : (float) $quote->line)) < .001);
                if (! $other) {
                    continue;
                }
                $result[$key] = [...$this->freeze($quote), 'opposite_quote' => $this->freeze($other)];
                break;
            }
            if (! $result[$key]) {
                $result['missing'][] = $key.'_paired_quote_unavailable';
            }
        }

        return [...$result, 'available' => $result['spread'] !== null || $result['total'] !== null];
    }

    private function safe(MarketQuote $quote, CarbonImmutable $asOf, CarbonImmutable $kickoff): bool
    {
        if (! $quote->game_odds_snapshot_id || ! $quote->quote_hash || ! is_numeric($quote->line) || ! is_finite((float) $quote->line) || ! is_numeric($quote->price) || abs((int) $quote->price) < 100
            || ! $quote->captured_at || ! $quote->created_at || $quote->captured_at->gt($asOf) || $quote->created_at->gt($asOf)
            || $quote->captured_at->gte($kickoff) || $quote->created_at->gte($kickoff)
            || $quote->captured_at->lt($asOf->subHours(36))) {
            return false;
        }
        $observed = data_get($quote->metadata, 'provider_observed_at');
        try {
            return ! $observed || (CarbonImmutable::parse($observed)->lte($asOf) && CarbonImmutable::parse($observed)->gte($asOf->subHours(36)) && CarbonImmutable::parse($observed)->lt($kickoff));
        } catch (\Throwable) {
            return false;
        }
    }

    private function participantMatches(Game $game, MarketQuote $quote, ?string $side): bool
    {
        if ($side === null) {
            return (float) $quote->line > 0;
        }
        $team = $game->{$side.'Team'};
        $names = collect([$team?->display_name, $team?->name, $team?->school, $team?->short_display_name, $team?->abbreviation,
            trim(($team?->school ?? '').' '.($team?->mascot ?? ''))])->filter()->map(fn ($n) => strtolower(trim($n)));

        return $names->contains(strtolower(trim((string) $quote->participant)));
    }

    private function freeze(MarketQuote $quote): array
    {
        return ['quote_id' => $quote->id, 'quote_hash' => $quote->quote_hash, 'snapshot_id' => $quote->game_odds_snapshot_id,
            'bookmaker' => $quote->bookmaker_key, 'side' => $quote->side, 'participant' => $quote->participant,
            'line' => (float) $quote->line, 'price' => (int) $quote->price, 'contract' => CfbStoredPregameQuote::contract($quote),
            'captured_at' => $quote->captured_at->toIso8601String(), 'stored_at' => $quote->created_at->toIso8601String(),
            'provider_observed_at' => data_get($quote->metadata, 'provider_observed_at')];
    }
}
