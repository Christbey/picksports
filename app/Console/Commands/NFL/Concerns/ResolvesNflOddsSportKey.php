<?php

namespace App\Console\Commands\NFL\Concerns;

use App\Console\Commands\Sports\Concerns\ResolvesSeasonalOddsSportKey;

trait ResolvesNflOddsSportKey
{
    use ResolvesSeasonalOddsSportKey;

    protected const NFL_REGULAR_ODDS_SPORT_KEY = 'americanfootball_nfl';
    protected const NFL_PRESEASON_ODDS_SPORT_KEY = 'americanfootball_nfl_preseason';
    protected const NFL_ODDS_DETECTION_WINDOW_DAYS = 14;

    protected function resolveAutomaticNflOddsSportKey(): string
    {
        return $this->resolveAutomaticSeasonalOddsSportKey(
            \App\Models\NFL\Game::class,
            'nfl',
            self::NFL_REGULAR_ODDS_SPORT_KEY,
            self::NFL_PRESEASON_ODDS_SPORT_KEY,
            'preseason',
            self::NFL_ODDS_DETECTION_WINDOW_DAYS,
        );
    }
}
