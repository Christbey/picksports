<?php

namespace App\Console\Commands\NBA\Concerns;

use App\Console\Commands\Sports\Concerns\ResolvesSeasonalOddsSportKey;

trait ResolvesNbaOddsSportKey
{
    use ResolvesSeasonalOddsSportKey;

    protected const NBA_REGULAR_ODDS_SPORT_KEY = 'basketball_nba';

    protected const NBA_PRESEASON_ODDS_SPORT_KEY = 'basketball_nba_preseason';

    protected const NBA_ODDS_DETECTION_WINDOW_DAYS = 14;

    protected function resolveAutomaticNbaOddsSportKey(): string
    {
        return $this->resolveAutomaticSeasonalOddsSportKey(
            \App\Models\NBA\Game::class,
            'nba',
            self::NBA_REGULAR_ODDS_SPORT_KEY,
            self::NBA_PRESEASON_ODDS_SPORT_KEY,
            'preseason',
            self::NBA_ODDS_DETECTION_WINDOW_DAYS,
        );
    }
}
