<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'baseball_mlb';
    protected const DEFAULT_MARKETS = self::MARKETS_STANDARD;
    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;
}
