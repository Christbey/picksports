<?php

namespace App\Services\CFB\Predictions;

use App\Models\BetDecision;
use App\Models\MarketQuote;
use Carbon\CarbonInterface;

/** The closing line is the last quote actually stored before kickoff, not a consensus or later revision. */
class CfbStoredPregameQuote
{
    public static function contract(MarketQuote $quote): array
    {
        $identity = [];
        foreach (['raw_market_key', 'period', 'includes_overtime', 'settlement_rules', 'alternate_id'] as $key) {
            $identity[$key] = data_get($quote->metadata, $key);
        }
        // Alternate ladders are distinct contracts: a different rung is not line movement.
        if (str_contains((string) ($identity['raw_market_key'] ?? $quote->market_key), 'alternate')) {
            $identity['__line'] = (float) $quote->line;
        }

        return $identity;
    }

    public static function identity(MarketQuote $quote): string
    {
        return json_encode([$quote->bookmaker_key, $quote->market_key, $quote->side, $quote->participant, self::contract($quote)], JSON_THROW_ON_ERROR);
    }

    public function forDecision(BetDecision $decision, CarbonInterface $kickoff): ?MarketQuote
    {
        if (! $decision->bookmaker) {
            return null;
        }
        $entryId = data_get($decision->market_snapshot, 'quote.quote_id');
        $entry = $entryId ? MarketQuote::whereKey($entryId)->where('sport', 'cfb')->where('game_id', $decision->game_id)
            ->where('bookmaker_key', $decision->bookmaker)->where('side', $decision->side)->first() : null;
        if ($entryId && ! $entry) {
            return null;
        }
        $market = $entry?->market_key ?? $decision->market_key ?? match ($decision->market_type) {
            'spread' => 'spreads', 'total' => 'totals', 'moneyline' => 'h2h', default => $decision->market_type,
        };
        // Legacy records without a frozen quote can safely resolve only standard game markets.
        if (! $entry && ! in_array($market, ['spreads', 'totals', 'h2h'], true)) {
            return null;
        }

        return $this->latest((int) $decision->game_id, $market, (string) $decision->side, $kickoff,
            bookmaker: $decision->bookmaker, participant: $entry?->participant,
            contract: $entry ? [...self::contract($entry), '__participant' => $entry->participant] : []);
    }

    public function latest(int $gameId, string $marketKey, string $side, CarbonInterface $kickoff, ?CarbonInterface $asOf = null, ?string $bookmaker = null, ?string $participant = null, array $contract = []): ?MarketQuote
    {
        // Database timestamps use the application timezone, including UTC callers.
        $kickoff = $kickoff->copy()->setTimezone(config('app.timezone'));
        $asOf = $asOf?->copy()->setTimezone(config('app.timezone'));

        return MarketQuote::query()->where('sport', 'cfb')->where('game_table', 'cfb_games')
            ->where('game_id', $gameId)->where('market_key', $marketKey)->where('side', $side)
            ->when($bookmaker !== null, fn ($q) => $q->where('bookmaker_key', $bookmaker))
            ->when($participant !== null, fn ($q) => $q->where('participant', $participant))
            ->where('is_pregame', true)->where('captured_at', '<', $kickoff)
            ->where('created_at', '<', $kickoff)
            ->when($asOf, fn ($query) => $query->where('captured_at', '<=', $asOf)->where('created_at', '<=', $asOf))
            ->orderByDesc('created_at')->orderByDesc('id')->cursor()->first(function ($quote) use ($contract) {
                foreach ($contract as $key => $value) {
                    if (match ($key) {
                        '__line' => (float) $quote->line, '__participant' => $quote->participant, default => data_get($quote->metadata, $key)
                    } !== $value) {
                        return false;
                    }
                }

                return true;
            });
    }
}
