<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'baseball_mlb';

    protected const PRESEASON_SPORT_KEY = 'baseball_mlb_preseason';

    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = false;

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
}
