<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'baseball_mlb';
    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = false;
}
