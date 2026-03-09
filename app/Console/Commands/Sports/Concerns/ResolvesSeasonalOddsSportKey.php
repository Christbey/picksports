<?php

namespace App\Console\Commands\Sports\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

trait ResolvesSeasonalOddsSportKey
{
    protected function resolveAutomaticSeasonalOddsSportKey(
        string $modelClass,
        string $configPrefix,
        string $regularOddsSportKey,
        string $preseasonOddsSportKey,
        string $preseasonSeasonTypeConfigKey = 'preseason',
        int $windowDays = 14,
    ): string {
        /** @var class-string<Model> $modelClass */
        $today = Carbon::now()->startOfDay();
        $windowEnd = Carbon::now()->addDays($windowDays)->endOfDay();
        $regularSeasonType = (int) config("{$configPrefix}.season.types.regular", 2);
        $preseasonSeasonType = (int) config("{$configPrefix}.season.types.{$preseasonSeasonTypeConfigKey}", 1);

        $seasonTypes = $modelClass::query()
            ->whereBetween('game_date', [$today, $windowEnd])
            ->whereIn('season_type', [$regularSeasonType, $preseasonSeasonType])
            ->distinct()
            ->pluck('season_type')
            ->map(static fn ($seasonType): int => (int) $seasonType)
            ->all();

        if (in_array($regularSeasonType, $seasonTypes, true)) {
            return $regularOddsSportKey;
        }

        if (in_array($preseasonSeasonType, $seasonTypes, true)) {
            return $preseasonOddsSportKey;
        }

        return $regularOddsSportKey;
    }
}
