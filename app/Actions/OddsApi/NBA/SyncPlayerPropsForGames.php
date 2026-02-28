<?php

namespace App\Actions\OddsApi\NBA;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_nba';
    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;
}
