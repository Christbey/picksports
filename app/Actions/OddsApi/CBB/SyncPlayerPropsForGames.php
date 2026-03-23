<?php

namespace App\Actions\OddsApi\CBB;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerProp;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_ncaab';

    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = Player::class;
}
