<?php

namespace App\Actions\OddsApi\NBA;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_nba';

    protected const PRESEASON_SPORT_KEY = 'basketball_nba_preseason';

    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('nba.season.types.preseason', 1);
        }

        return null;
    }
}
