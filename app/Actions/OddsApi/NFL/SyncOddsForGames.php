<?php

namespace App\Actions\OddsApi\NFL;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'americanfootball_nfl';
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;
}
