<?php

namespace App\Support\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class MlbQuoteTime
{
    public const POLICY = 'mlb-quote-utc-epoch-v1';

    /** New observations only. The caller's legacy SQL timestamp is never used to reconstruct an old marker. */
    public static function capture(Game $game, CarbonInterface $captured, ?CarbonInterface $commence, ?string $providerId, string $payloadHash, array $oddsData): ?array
    {
        if (MlbGameStart::for($game) === null || $commence === null) {
            return null;
        }
        $body = [
            'policy' => self::POLICY, 'game_id' => (int) $game->id, 'game_table' => $game->getTable(),
            'provider_event_id' => $providerId, 'payload_hash' => $payloadHash, 'data_hash' => app(CanonicalPayloadHasher::class)->hash($oddsData),
            'game_start_epoch' => (int) $game->start_epoch, 'game_context_hash' => $game->start_context_hash,
            'game_source_hash' => $game->start_source_hash,
            'captured_epoch' => $captured->getTimestamp(), 'captured_at_utc' => $captured->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'commence_epoch' => $commence->getTimestamp(), 'observed_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
        ];

        return $body + ['hash' => app(CanonicalPayloadHasher::class)->hash($body)];
    }

    public static function marker(Game $game, GameOddsSnapshot $snapshot): ?array
    {
        try {
            $marker = data_get($snapshot->market_context, '_mlb_time');
            if (! is_array($marker) || ! is_string($marker['hash'] ?? null) || MlbGameStart::for($game) === null) {
                return null;
            }
            $body = $marker;
            unset($body['hash']);
            if (! hash_equals($marker['hash'], app(CanonicalPayloadHasher::class)->hash($body))
                || ($body['policy'] ?? null) !== self::POLICY || ($body['game_id'] ?? null) !== (int) $game->id
                || ($body['game_table'] ?? null) !== $game->getTable() || $snapshot->sport !== 'mlb'
                || $snapshot->game_table !== $game->getTable() || (int) $snapshot->game_id !== (int) $game->id
                || ($body['provider_event_id'] ?? null) !== $snapshot->odds_api_event_id
                || ($body['payload_hash'] ?? null) !== $snapshot->payload_hash
                || ($body['data_hash'] ?? null) !== app(CanonicalPayloadHasher::class)->hash($snapshot->odds_data)
                || ($body['game_start_epoch'] ?? null) !== (int) $game->start_epoch
                || ($body['game_context_hash'] ?? null) !== $game->start_context_hash
                || ($body['game_source_hash'] ?? null) !== $game->start_source_hash
                || ! is_int($body['captured_epoch'] ?? null) || ! is_int($body['commence_epoch'] ?? null)
                || ! is_string($body['captured_at_utc'] ?? null) || ! is_string($body['observed_at_utc'] ?? null)
                || CarbonImmutable::parse($body['captured_at_utc'], 'UTC')->getTimestamp() !== $body['captured_epoch']) {
                return null;
            }

            CarbonImmutable::parse($body['observed_at_utc'], 'UTC');

            return $marker;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function pregame(Game $game, GameOddsSnapshot $snapshot, ?CarbonInterface $availableBy = null): bool
    {
        $marker = self::marker($game, $snapshot);
        if ($marker === null) {
            return false;
        }
        $observed = CarbonImmutable::parse($marker['observed_at_utc']);
        $captured = CarbonImmutable::parse($marker['captured_at_utc']);
        $start = MlbGameStart::for($game);

        return $marker['commence_epoch'] === $start->getTimestamp() && $captured->lt($start) && $observed->lt($start)
            && MlbGameStart::observedBy($game, $captured)
            && ($availableBy === null || ($observed->lte($availableBy) && $captured->lte($availableBy)));
    }

    public static function quote(Game $game, MarketQuote $quote): bool
    {
        $snapshot = $quote->gameOddsSnapshot;

        return $snapshot !== null && $quote->sport === 'mlb' && (int) $quote->game_id === (int) $game->id
            && app(CanonicalPayloadHasher::class)->encode(data_get($quote->metadata, '_mlb_time')) === app(CanonicalPayloadHasher::class)->encode(data_get($snapshot->market_context, '_mlb_time'))
            && data_get($quote->metadata, '_mlb_quote_value_hash') === self::quoteValueHash($quote->getAttributes())
            && self::pregame($game, $snapshot);
    }

    public static function quoteValueHash(array $row): string
    {
        $line = $row['line'] ?? null;
        $normalizedLine = $line === null ? null : number_format((float) $line, 3, '.', '');
        if ($normalizedLine === '-0.000') {
            $normalizedLine = '0.000';
        }

        return app(CanonicalPayloadHasher::class)->hash([
            'snapshot_id' => (int) ($row['game_odds_snapshot_id'] ?? 0), 'game_id' => (int) ($row['game_id'] ?? 0),
            'sport' => $row['sport'] ?? null, 'game_table' => $row['game_table'] ?? null, 'source' => $row['source'] ?? null,
            'bookmaker_key' => $row['bookmaker_key'] ?? null, 'market_key' => $row['market_key'] ?? null,
            'side' => $row['side'] ?? null, 'participant' => $row['participant'] ?? null,
            'line' => $normalizedLine, 'price' => isset($row['price']) ? (int) $row['price'] : null,
        ]);
    }

    /** Finite candidate scan; missing/invalid provenance returns unavailable rather than guessing a legacy clock. */
    public static function latest(Game $game): ?GameOddsSnapshot
    {
        if (MlbGameStart::for($game) === null || (new GameOddsSnapshot)->getConnection()->getName() !== $game->getConnection()->getName()) {
            return null;
        }
        $current = $game->newQuery()->lockForUpdate()->find($game->id);
        if ($current === null || MlbGameStart::for($current) === null || $current->start_context_hash !== $game->start_context_hash) {
            return null;
        }
        $game = $current;
        $candidates = GameOddsSnapshot::query()->where('sport', 'mlb')->where('game_table', $game->getTable())
            ->where('game_id', $game->id)->where('market_context->_mlb_time->policy', self::POLICY)
            ->where('market_context->_mlb_time->captured_epoch', '<', (int) $game->start_epoch)
            ->orderByDesc('market_context->_mlb_time->captured_epoch')->orderByDesc('id')->limit(25)->get();

        return $candidates->first(fn (GameOddsSnapshot $snapshot): bool => self::pregame($game, $snapshot));
    }
}
