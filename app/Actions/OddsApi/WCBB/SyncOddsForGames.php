<?php

namespace App\Actions\OddsApi\WCBB;

use App\Actions\OddsApi\AbstractCollegeBasketballSyncOddsForGames;

class SyncOddsForGames extends AbstractCollegeBasketballSyncOddsForGames
{
    protected const SPORT_KEY = 'basketball_wncaab';
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;
}
