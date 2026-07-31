<?php

namespace App\Support\CFB;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class CfbWeek
{
    public const REGULAR_SEASON_TYPE = 2;

    public static function productWeekForGame(
        int $season,
        int $seasonType,
        int $espnWeek,
        mixed $gameDate,
        mixed $gameTime = null,
    ): int {
        if ($seasonType !== self::REGULAR_SEASON_TYPE || $gameDate === null) {
            return $espnWeek;
        }

        $easternDate = self::gameDateInEastern($gameDate, $gameTime);

        if ($easternDate->isSameDay(self::weekZeroDate($season))) {
            return 0;
        }

        return max(1, $espnWeek);
    }

    public static function productWeekForDate(int $season, CarbonInterface|string|null $date = null): int
    {
        $referenceDate = self::dateInEastern($date)->startOfDay();
        $weekOneStart = self::weekOneStartDate($season);

        if ($referenceDate->lt($weekOneStart)) {
            return 0;
        }

        $weeksSinceWeekOne = intdiv((int) $weekOneStart->diffInDays($referenceDate), 7);

        return min($weeksSinceWeekOne + 1, 15);
    }

    public static function espnWeekForProductWeek(int $seasonType, int $week): int
    {
        if ($seasonType === self::REGULAR_SEASON_TYPE && $week === 0) {
            return 1;
        }

        return $week;
    }

    public static function weekZeroDate(int $season): Carbon
    {
        return Carbon::create($season, 9, 1, 0, 0, 0, 'America/New_York')
            ->firstOfMonth(CarbonInterface::MONDAY)
            ->subDays(9)
            ->startOfDay();
    }

    public static function weekOneStartDate(int $season): Carbon
    {
        return self::weekZeroDate($season)->addDays(5)->startOfDay();
    }

    private static function gameDateInEastern(mixed $gameDate, mixed $gameTime = null): Carbon
    {
        if ($gameTime === null) {
            if ($gameDate instanceof CarbonInterface) {
                return Carbon::instance($gameDate)->setTimezone('America/New_York')->startOfDay();
            }

            return Carbon::parse((string) $gameDate, 'UTC')->setTimezone('America/New_York')->startOfDay();
        }

        $date = is_object($gameDate) && method_exists($gameDate, 'toDateString')
            ? $gameDate->toDateString()
            : Carbon::parse((string) $gameDate)->toDateString();
        $time = self::timeString($gameTime) ?? '00:00:00';

        return Carbon::parse("{$date} {$time}", 'UTC')->setTimezone('America/New_York')->startOfDay();
    }

    private static function dateInEastern(CarbonInterface|string|null $date = null): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date)->setTimezone('America/New_York');
        }

        return $date === null
            ? now('America/New_York')
            : Carbon::parse($date, 'America/New_York');
    }

    private static function timeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('H:i:s');
        }

        $time = (string) $value;

        if (preg_match('/\b(\d{2}:\d{2}(?::\d{2})?)\b/', $time, $matches) === 1) {
            return strlen($matches[1]) === 5 ? "{$matches[1]}:00" : $matches[1];
        }

        return $time;
    }
}
