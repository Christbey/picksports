<?php

namespace App\Console\Commands\MLB\Concerns;

use App\Console\Commands\Sports\Concerns\ResolvesSeasonalOddsSportKey;
use App\Models\MLB\Game;

trait ResolvesMlbOddsSportKey
{
    use ResolvesSeasonalOddsSportKey;

    protected const MLB_REGULAR_ODDS_SPORT_KEY = 'baseball_mlb';

    protected const MLB_PRESEASON_ODDS_SPORT_KEY = 'baseball_mlb_preseason';

    protected const MLB_ODDS_DETECTION_WINDOW_DAYS = 14;

    protected function resolveAutomaticMlbOddsSportKey(): string
    {
        return $this->resolveAutomaticSeasonalOddsSportKey(
            Game::class,
            'mlb',
            self::MLB_REGULAR_ODDS_SPORT_KEY,
            self::MLB_PRESEASON_ODDS_SPORT_KEY,
            'spring_training',
            self::MLB_ODDS_DETECTION_WINDOW_DAYS,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAutomaticMlbOddsSportKeys(int $windowDays = self::MLB_ODDS_DETECTION_WINDOW_DAYS): array
    {
        $today = now()->startOfDay();
        $windowEnd = now()->copy()->addDays($windowDays)->endOfDay();
        $regularSeasonType = (string) config('mlb.season.types.regular', 2);
        $springTrainingSeasonType = (string) config('mlb.season.types.spring_training', 1);

        $seasonTypes = Game::query()
            ->whereBetween('game_date', [$today, $windowEnd])
            ->distinct()
            ->pluck('season_type')
            ->map(static fn ($seasonType): string => trim((string) $seasonType))
            ->filter()
            ->all();

        $keys = [];

        if (in_array($springTrainingSeasonType, $seasonTypes, true) || in_array('Spring Training', $seasonTypes, true) || in_array('Preseason', $seasonTypes, true)) {
            $keys[] = self::MLB_PRESEASON_ODDS_SPORT_KEY;
        }

        if (in_array($regularSeasonType, $seasonTypes, true) || in_array('Regular Season', $seasonTypes, true) || in_array('Regular', $seasonTypes, true)) {
            $keys[] = self::MLB_REGULAR_ODDS_SPORT_KEY;
        }

        return $keys !== [] ? array_values(array_unique($keys)) : [self::MLB_REGULAR_ODDS_SPORT_KEY];
    }
}
