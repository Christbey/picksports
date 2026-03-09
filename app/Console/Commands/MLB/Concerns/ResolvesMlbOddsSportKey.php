<?php

namespace App\Console\Commands\MLB\Concerns;

use App\Console\Commands\Sports\Concerns\ResolvesSeasonalOddsSportKey;

trait ResolvesMlbOddsSportKey
{
    use ResolvesSeasonalOddsSportKey;

    protected const MLB_REGULAR_ODDS_SPORT_KEY = 'baseball_mlb';
    protected const MLB_PRESEASON_ODDS_SPORT_KEY = 'baseball_mlb_preseason';
    protected const MLB_ODDS_DETECTION_WINDOW_DAYS = 14;

    protected function resolveAutomaticMlbOddsSportKey(): string
    {
        return $this->resolveAutomaticSeasonalOddsSportKey(
            \App\Models\MLB\Game::class,
            'mlb',
            self::MLB_REGULAR_ODDS_SPORT_KEY,
            self::MLB_PRESEASON_ODDS_SPORT_KEY,
            'spring_training',
            self::MLB_ODDS_DETECTION_WINDOW_DAYS,
        );
    }
}
