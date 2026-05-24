<?php

namespace App\Actions\OddsApi\WNBA;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;
use App\Models\WNBA\Game;
use App\Models\WNBA\Player;
use App\Models\WNBA\PlayerProp;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_wnba';

    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = Player::class;
}
