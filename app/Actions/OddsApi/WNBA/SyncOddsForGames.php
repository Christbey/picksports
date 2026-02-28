<?php

namespace App\Actions\OddsApi\WNBA;

use App\Actions\OddsApi\AbstractSyncOddsForGames;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_wnba';
    protected const GAME_MODEL_CLASS = \App\Models\WNBA\Game::class;
}
