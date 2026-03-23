<?php

namespace App\Actions\OddsApi\NFL;

use App\Actions\OddsApi\AbstractSportKeySyncPlayerPropsForGames;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;

class SyncPlayerPropsForGames extends AbstractSportKeySyncPlayerPropsForGames
{
    protected const SPORT_KEY = 'americanfootball_nfl';

    protected const PRESEASON_SPORT_KEY = 'americanfootball_nfl_preseason';

    protected const DEFAULT_MARKETS = self::MARKETS_STANDARD;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAYER_PROP_MODEL_CLASS = PlayerProp::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('nfl.season.types.preseason', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('nfl.season.types.regular', 2);
        }

        return null;
    }
}
