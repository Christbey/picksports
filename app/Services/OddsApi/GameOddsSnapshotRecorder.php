<?php

namespace App\Services\OddsApi;

use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbQuoteTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GameOddsSnapshotRecorder
{
    /**
     * @param  array<string, mixed>  $rawEvent
     * @param  array<string, mixed>  $oddsData
     */
    public function record(
        string $sport,
        Model $game,
        array $rawEvent,
        array $oddsData,
        ?Carbon $capturedAt = null,
        string $source = 'odds_api'
    ): ?GameOddsSnapshot {
        if ($sport === 'mlb' && $game instanceof Game) {
            foreach ([new GameOddsSnapshot, new MarketQuote] as $model) {
                if ($model->getConnection()->getName() !== $game->getConnection()->getName()) {
                    throw new \RuntimeException('MLB quote observations require one coherent database connection.');
                }
            }

            return $game->getConnection()->transaction(function () use ($sport, $game, $rawEvent, $oddsData, $capturedAt, $source): ?GameOddsSnapshot {
                $current = $game->newQuery()->lockForUpdate()->findOrFail($game->id);
                if (MlbGameStart::context($current) !== MlbGameStart::context($game)) {
                    throw new \RuntimeException('MLB schedule changed before quote recording; retry with current source facts.');
                }

                return $this->recordLocal($sport, $current, $rawEvent, $oddsData, $capturedAt, $source);
            });
        }

        return $this->recordLocal($sport, $game, $rawEvent, $oddsData, $capturedAt, $source);
    }

    private function recordLocal(string $sport, Model $game, array $rawEvent, array $oddsData, ?Carbon $capturedAt, string $source): ?GameOddsSnapshot
    {
        $capturedAt ??= now();
        $payloadHash = hash('sha256', json_encode($oddsData));
        $marketContext = is_array($oddsData['market_context'] ?? null) ? $oddsData['market_context'] : [];
        if ($sport === 'mlb') {
            unset($marketContext['_mlb_time']);
            if ($game instanceof Game) {
                $marker = MlbQuoteTime::capture($game, $capturedAt, $this->commenceTime($rawEvent['commence_time'] ?? null), isset($rawEvent['id']) ? (string) $rawEvent['id'] : null, $payloadHash, $oddsData);
                if ($marker !== null) {
                    $marketContext['_mlb_time'] = $marker;
                }
            }
        }

        $latestSnapshot = GameOddsSnapshot::query()
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->latest('captured_at')
            ->first();

        if ($latestSnapshot && (string) $latestSnapshot->payload_hash === $payloadHash) {
            // A fresh successful fetch can confirm an unchanged market. Keep
            // that observation immutable instead of letting deduplication make
            // pregame quotes permanently stale after their first capture.
            $refreshAfterMinutes = max(1, (int) floor(
                ($sport === 'cfb'
                    ? (int) config('cfb.predictions.spread_value.maximum_quote_age_minutes', 60)
                    : (int) config('nfl.predictions.pregame_market.maximum_quote_age_minutes', 60)) / 2,
            ));
            if ($sport === 'mlb' && $game instanceof Game) {
                $prior = MlbQuoteTime::marker($game, $latestSnapshot);
                $refreshMinutes = max(1, (int) floor((float) config('mlb.signals.odds_stale_hours', 12) * 30));
                if ($prior !== null && CarbonImmutable::parse($prior['observed_at_utc'])->gt(now()->subMinutes($refreshMinutes))) {
                    return null;
                }
            } elseif (! in_array($sport, ['nfl', 'cfb', 'cbb'], true)
                || $latestSnapshot->captured_at === null
                || $latestSnapshot->captured_at->gt($capturedAt->copy()->subMinutes($refreshAfterMinutes))) {
                return null;
            }
        }

        $snapshot = GameOddsSnapshot::query()->create([
            'sport' => $sport,
            'game_table' => $game->getTable(),
            'game_id' => (int) $game->getKey(),
            'odds_api_event_id' => isset($rawEvent['id']) ? (string) $rawEvent['id'] : null,
            'bookmaker_key' => data_get($oddsData, 'bookmakers.0.key'),
            'bookmaker_title' => data_get($oddsData, 'bookmakers.0.title'),
            'source' => $source,
            'commence_time' => $this->commenceTime($rawEvent['commence_time'] ?? null),
            'captured_at' => $this->storageTimestamp($capturedAt),
            'payload_hash' => $payloadHash,
            'odds_data' => $oddsData,
            'market_context' => $sport === 'mlb' ? $marketContext : (is_array($oddsData['market_context'] ?? null) ? $oddsData['market_context'] : null),
        ]);

        app(MarketQuoteRecorder::class)->record($snapshot, $oddsData);

        return $snapshot;
    }

    private function commenceTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->storageTimestamp(Carbon::parse($value));
    }

    private function storageTimestamp(CarbonInterface $timestamp): Carbon
    {
        return Carbon::instance($timestamp instanceof \Carbon\Carbon ? $timestamp : $timestamp->toMutable())
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
