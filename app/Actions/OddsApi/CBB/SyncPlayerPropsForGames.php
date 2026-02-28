<?php

namespace App\Actions\OddsApi\CBB;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_ncaab';
    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;
}
