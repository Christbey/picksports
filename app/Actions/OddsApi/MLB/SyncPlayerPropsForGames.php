<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'baseball_mlb';

    protected const PRESEASON_SPORT_KEY = 'baseball_mlb_preseason';

    protected const DEFAULT_MARKETS = self::MARKETS_STANDARD;

    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = \App\Models\MLB\PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\MLB\Player::class;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('mlb.season.types.spring_training', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('mlb.season.types.regular', 2);
        }

        return null;
    }
}
