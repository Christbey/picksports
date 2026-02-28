<?php

namespace App\Actions\OddsApi\NFL;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'americanfootball_nfl';
    protected const DEFAULT_MARKETS = self::MARKETS_STANDARD;
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;
}
