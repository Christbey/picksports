<?php

namespace App\Services\MLB\Odds;

use App\Models\MLB\Game;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;

class MlbInningOddsSyncService
{
    public function __construct(
        private readonly OddsApiService $odds,
        private readonly GameOddsSnapshotRecorder $snapshots,
        private readonly SportsViewCache $cache,
    ) {}

    /**
     * @param  list<string>  $markets
     * @return array{games:int,updated:int,market_rows:int,missing_event_ids:int}
     */
    public function sync(
        int $days = 1,
        array $markets = [],
        ?string $bookmaker = null,
        bool $dryRun = false,
    ): array {
        $days = max(0, $days);
        $markets = $markets !== [] ? $markets : (array) config('mlb.picks.inning_odds.markets', []);
        $bookmaker ??= (string) config('mlb.picks.inning_odds.bookmaker', 'draftkings');
        $games = Game::query()
            ->whereDate('game_date', '>=', now()->toDateString())
            ->whereDate('game_date', '<=', now()->addDays($days)->toDateString())
            ->where('status', config('mlb.statuses.scheduled', 'STATUS_SCHEDULED'))
            ->orderBy('game_date')
            ->get();

        $updated = 0;
        $marketRows = 0;
        $missingEventIds = 0;

        foreach ($games as $game) {
            if (! is_string($game->odds_api_event_id) || $game->odds_api_event_id === '') {
                $missingEventIds++;

                continue;
            }

            $event = $this->odds->getEventOdds(
                'baseball_mlb',
                $game->odds_api_event_id,
                $markets,
                $bookmaker,
            );
            if (! is_array($event)) {
                continue;
            }

            $additional = $this->odds->extractOddsData($event);
            $marketCount = collect((array) data_get($additional, 'bookmakers', []))
                ->sum(fn (array $row): int => count((array) ($row['markets'] ?? [])));
            if ($marketCount === 0) {
                continue;
            }

            $merged = $this->mergeOddsData((array) $game->odds_data, $additional);
            $marketRows += $marketCount;

            if (! $dryRun) {
                $this->snapshots->record('mlb', $game, $event, $merged, source: 'odds_api_inning_markets');
                $game->update([
                    'odds_data' => $merged,
                    'odds_updated_at' => now(),
                ]);
            }

            $updated++;
        }

        if (! $dryRun && $updated > 0) {
            $this->cache->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
                SportsViewCache::SEGMENT_TEAM_GAMES_BY_TEAM,
            ]);
        }

        return [
            'games' => $games->count(),
            'updated' => $updated,
            'market_rows' => $marketRows,
            'missing_event_ids' => $missingEventIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    private function mergeOddsData(array $current, array $additional): array
    {
        $merged = [
            ...$current,
            ...collect($additional)->except(['bookmakers', 'market_context'])->all(),
        ];
        $bookmakers = collect((array) ($current['bookmakers'] ?? []))
            ->keyBy(fn (array $row): string => (string) ($row['key'] ?? $row['title'] ?? 'unknown'));

        foreach ((array) ($additional['bookmakers'] ?? []) as $additionalBookmaker) {
            if (! is_array($additionalBookmaker)) {
                continue;
            }

            $key = (string) ($additionalBookmaker['key'] ?? $additionalBookmaker['title'] ?? 'unknown');
            $existing = (array) $bookmakers->get($key, []);
            $markets = collect((array) ($existing['markets'] ?? []))
                ->keyBy(fn (array $row): string => (string) ($row['key'] ?? 'unknown'));

            foreach ((array) ($additionalBookmaker['markets'] ?? []) as $market) {
                if (is_array($market) && is_string($market['key'] ?? null)) {
                    $markets->put($market['key'], $market);
                }
            }

            $bookmakers->put($key, [
                ...$existing,
                ...$additionalBookmaker,
                'markets' => $markets->values()->all(),
            ]);
        }

        $merged['bookmakers'] = $bookmakers->values()->all();
        $merged['market_context'] = $this->odds->marketAvailability($merged);

        return $merged;
    }
}
