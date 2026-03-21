<?php

namespace App\Actions\OddsApi\NFL;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'americanfootball_nfl';

    protected const PRESEASON_SPORT_KEY = 'americanfootball_nfl_preseason';

    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;

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
}
