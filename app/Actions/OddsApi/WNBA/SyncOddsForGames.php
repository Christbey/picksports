<?php

namespace App\Actions\OddsApi\WNBA;

use App\Actions\OddsApi\AbstractSyncOddsForGames;
use App\Models\WNBA\Game;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_wnba';

    protected const GAME_MODEL_CLASS = Game::class;
}
