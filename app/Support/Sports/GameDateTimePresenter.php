<?php

namespace App\Support\Sports;

use App\Models\NFL\Game;
use App\Support\CFB\CfbGameStart;
use App\Support\MLB\MlbGameStart;
use App\Support\NFL\NflGameStart;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

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

    /** NFL game date/time storage is UTC, independently of the display timezone. */
    /** ESPN's shared GameData writers store these sports in UTC. MLB has mixed venue-local history. */
    public static function utcStoredStartsAt(string $sport, mixed $gameDate, mixed $gameTime): ?string
    {
        if (! in_array($sport, ['nfl', 'cfb', 'nba', 'wnba', 'cbb', 'wcbb'], true)) {
            return null;
        }

        return self::nflStartsAt($gameDate, $gameTime);
    }

    public static function nflStartsAt(mixed $gameDate, mixed $gameTime): ?string
    {
        if ($gameDate === null || $gameTime === null) {
            return null;
        }
        $time = is_object($gameTime) && method_exists($gameTime, 'format') ? $gameTime->format('H:i:s') : (is_string($gameTime) ? trim($gameTime) : '');
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?$/D', $time) !== 1) {
            return null;
        }
        $date = is_object($gameDate) && method_exists($gameDate, 'toDateString') ? $gameDate->toDateString() : (is_string($gameDate) ? substr($gameDate, 0, 10) : '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::parse($date.' '.$time, 'UTC')->format(str_contains($time, '.') ? 'Y-m-d\\TH:i:s.uP' : 'Y-m-d\\TH:i:sP');
    }

    public static function forGame(string $sport, Model $game): array
    {
        $date = substr((string) $game->getRawOriginal('game_date'), 0, 10);
        $validDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        if ($game instanceof \App\Models\CFB\Game && CfbGameStart::for($game) === null) {
            return ['game_date' => $validDate ? $date : null, 'game_time' => null];
        }
        $time = $game->getRawOriginal('game_time');
        $validTime = is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?$/D', $time) === 1;
        if (! $validDate || ! $validTime || ($game instanceof Game && NflGameStart::knownMissingImportedClock($game))) {
            return ['game_date' => $validDate ? $date : null, 'game_time' => null];
        }

        return self::forSport($sport, $game->getAttribute('game_date'), $game->getAttribute('game_time'));
    }

    public static function startsAtForGame(string $sport, Model $game): ?string
    {
        if ($game instanceof \App\Models\CFB\Game) {
            return CfbGameStart::for($game)?->toIso8601String();
        }
        if ($game instanceof \App\Models\MLB\Game) {
            return MlbGameStart::for($game)?->toIso8601String();
        }

        return $game instanceof Game
            ? NflGameStart::for($game)?->toIso8601String()
            : self::utcStoredStartsAt($sport, $game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'));
    }

    /** Strict current split-field clock for the six UTC-storage models; never consult cached event relations. */
    public static function currentStoredStartsAt(Model $game): ?string
    {
        if ($game instanceof \App\Models\CFB\Game) {
            return CfbGameStart::for($game)?->toIso8601String();
        }
        $sport = match (true) {
            $game instanceof Game => 'nfl',
            $game instanceof \App\Models\CFB\Game => 'cfb',
            $game instanceof \App\Models\NBA\Game => 'nba',
            $game instanceof \App\Models\WNBA\Game => 'wnba',
            $game instanceof \App\Models\CBB\Game => 'cbb',
            $game instanceof \App\Models\WCBB\Game => 'wcbb',
            default => null,
        };
        if ($sport === null) {
            return null;
        }
        $attributes = $game->getAttributes();
        $clock = $attributes['game_time'] ?? null;
        if ($game instanceof Game && NflGameStart::knownMissingImportedClockObservation($game->nflverse_game_id, $clock, $game->odds_data)) {
            return null;
        }

        return self::utcStoredStartsAt($sport, $attributes['game_date'] ?? null, $clock);
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
        if (! in_array($sport, ['nfl', 'cfb', 'wnba'], true)) {
            return null;
        }

        return (string) config("trends.timezones.{$sport}.display", 'America/New_York');
    }
}
