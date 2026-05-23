<?php

namespace App\Actions\OddsApi\NBA;

use App\Actions\OddsApi\AbstractSyncOddsForGames;
use App\Models\NBA\Game;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_nba';

    protected const PRESEASON_SPORT_KEY = 'basketball_nba_preseason';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = true;

    protected const INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES = true;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('nba.season.types.preseason', 1);
        }

        return null;
    }
}
