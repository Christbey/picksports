<?php

namespace App\Support\Sports;

use Carbon\Carbon;

class GameDateTimePresenter
{
    /**
     * @return array{game_date:?string, game_time:?string}
     */
    public static function forSport(string $sport, mixed $gameDate, mixed $gameTime): array
    {
        $displayTimezone = self::displayTimezoneForSport($sport);
        $time = self::timeString($gameTime);

        if ($displayTimezone !== null && $gameDate !== null && $time !== null) {
            $utcDatetime = Carbon::parse(
                self::dateString($gameDate).' '.$time,
                'UTC',
            )->setTimezone($displayTimezone);

            return [
                'game_date' => $utcDatetime->toDateString(),
                'game_time' => $utcDatetime->toTimeString(),
            ];
        }

        if ($displayTimezone !== null && $gameDate !== null) {
            return [
                'game_date' => self::dateString($gameDate),
                'game_time' => $time,
            ];
        }

        return [
            'game_date' => self::serializeDateValue($gameDate),
            'game_time' => $time,
        ];
    }

    public static function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    public static function timeString(mixed $value): ?string
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

    private static function dateString(mixed $value): string
    {
        if (is_object($value) && method_exists($value, 'toDateString')) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private static function displayTimezoneForSport(string $sport): ?string
    {
        if (! in_array($sport, ['cfb', 'wnba'], true)) {
            return null;
        }

        return (string) config("trends.timezones.{$sport}.display", 'America/New_York');
    }
}
