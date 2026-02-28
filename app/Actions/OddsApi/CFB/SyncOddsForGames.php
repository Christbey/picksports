<?php

namespace App\Actions\OddsApi\CFB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'americanfootball_ncaaf';
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const MATCH_THRESHOLD = 85.0;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = true;
}
