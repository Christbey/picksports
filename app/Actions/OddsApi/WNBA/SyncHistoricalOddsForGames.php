<?php

namespace App\Actions\OddsApi\WNBA;

use App\Actions\OddsApi\AbstractSyncHistoricalOddsForGames;
use App\Models\WNBA\Game;

class SyncHistoricalOddsForGames extends AbstractSyncHistoricalOddsForGames
{
    protected const SPORT_KEY = 'basketball_wnba';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = true;

    protected const INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES = true;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        return $oddsSportKey === self::SPORT_KEY
            ? (int) config('wnba.season.types.regular', 2)
            : null;
    }
}
