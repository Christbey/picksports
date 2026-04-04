<?php

namespace App\Services\OddsApi;

use App\Models\GameOddsSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;

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
    ): ?GameOddsSnapshot
    {
        $capturedAt ??= now();
        $payloadHash = hash('sha256', json_encode($oddsData));

        $latestSnapshot = GameOddsSnapshot::query()
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->latest('captured_at')
            ->first();

        if ($latestSnapshot && (string) $latestSnapshot->payload_hash === $payloadHash) {
            return null;
        }

        return GameOddsSnapshot::query()->create([
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
            'market_context' => is_array($oddsData['market_context'] ?? null) ? $oddsData['market_context'] : null,
        ]);
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
