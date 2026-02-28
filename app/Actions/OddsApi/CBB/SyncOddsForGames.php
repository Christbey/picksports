<?php

namespace App\Actions\OddsApi\CBB;

use App\Actions\OddsApi\AbstractCollegeBasketballSyncOddsForGames;

class SyncOddsForGames extends AbstractCollegeBasketballSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_ncaab';
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;
}
