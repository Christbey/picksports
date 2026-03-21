<?php

namespace App\Actions\OddsApi\NBA;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'basketball_nba';

    protected const PRESEASON_SPORT_KEY = 'basketball_nba_preseason';

    protected const DEFAULT_MARKETS = self::MARKETS_BASKETBALL;

    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = \App\Models\NBA\PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\NBA\Player::class;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('nba.season.types.preseason', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('nba.season.types.regular', 2);
        }

        return null;
    }
}
