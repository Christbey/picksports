<?php

namespace App\Actions\OddsApi\NBA;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_nba';
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = true;

    protected const INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES = true;
}
