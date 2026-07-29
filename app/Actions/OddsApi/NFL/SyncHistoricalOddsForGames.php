<?php

namespace App\Actions\OddsApi\NFL;

use App\Actions\OddsApi\AbstractSyncHistoricalOddsForGames;
use App\Models\NFL\Game;
use Illuminate\Support\Carbon;

class SyncHistoricalOddsForGames extends AbstractSyncHistoricalOddsForGames
{
    private ?string $activeOddsSportKey = null;

    protected const SPORT_KEY = 'americanfootball_nfl';

    protected const PRESEASON_SPORT_KEY = 'americanfootball_nfl_preseason';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const HISTORICAL_MARKETS = 'h2h,spreads,totals';

    public function executeHistorical(
        int $hoursBefore = 24,
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 0,
        ?string $oddsSportKey = null,
        bool $hydrateCurrentWhenEmpty = false
    ): array {
        $previous = $this->activeOddsSportKey;
        $this->activeOddsSportKey = $oddsSportKey ?: $this->sportKey();

        try {
            return parent::executeHistorical(
                hoursBefore: $hoursBefore,
                season: $season,
                fromDate: $fromDate,
                toDate: $toDate,
                limit: $limit,
                oddsSportKey: $oddsSportKey,
                hydrateCurrentWhenEmpty: $hydrateCurrentWhenEmpty,
            );
        } finally {
            $this->activeOddsSportKey = $previous;
        }
    }

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('nfl.season.types.preseason', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('nfl.season.types.regular', 2);
        }

        return null;
    }

    /**
     * @return array{events: array<int, array<string, mixed>>, snapshot_timestamp: ?Carbon}
     */
    protected function historicalEventsAt(string $oddsSportKey, Carbon $targetTimestamp): array
    {
        $response = $this->oddsApiService->getHistoricalOdds(
            sport: $oddsSportKey,
            date: $targetTimestamp->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            markets: self::HISTORICAL_MARKETS
        );

        if (! is_array($response)) {
            return [
                'events' => [],
                'snapshot_timestamp' => null,
            ];
        }

        $events = $response['data'] ?? $response;
        $snapshotTimestamp = isset($response['timestamp']) && is_string($response['timestamp'])
            ? Carbon::parse($response['timestamp'])
            : null;

        return [
            'events' => is_array($events) ? array_values(array_filter($events, 'is_array')) : [],
            'snapshot_timestamp' => $snapshotTimestamp,
        ];
    }

    protected function historicalGamesQuery(
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 0
    ) {
        $query = parent::historicalGamesQuery($season, $fromDate, $toDate, $limit);

        $seasonType = $this->seasonTypeForOddsSportKey($this->activeOddsSportKey ?? $this->sportKey());
        if ($seasonType !== null) {
            $query->whereIn('season_type', $this->resolveSeasonTypeCandidates($seasonType));
        }

        return $query;
    }
}
