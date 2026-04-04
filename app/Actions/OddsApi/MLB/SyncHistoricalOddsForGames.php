<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSyncHistoricalOddsForGames;
use App\Models\MLB\Game;

class SyncHistoricalOddsForGames extends AbstractSyncHistoricalOddsForGames
{
    private ?string $activeOddsSportKey = null;

    protected const SPORT_KEY = 'baseball_mlb';

    protected const PRESEASON_SPORT_KEY = 'baseball_mlb_preseason';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = false;

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
            return (int) config('mlb.season.types.spring_training', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('mlb.season.types.regular', 2);
        }

        return null;
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
