<?php

namespace App\Support\NFL;

use App\Models\NFL\Game;
use App\Support\Sports\GameDateTimePresenter;
use Carbon\CarbonImmutable;

/** Stored-clock reads only. No history rewrite or source-time inference. */
final class NflGameStart
{
    public static function knownMissingImportedClock(Game $game): bool
    {
        return self::knownMissingImportedClockObservation($game->nflverse_game_id, $game->getRawOriginal('game_time'), $game->odds_data);
    }

    /** Same exact classification for bounded current raw-source probes; no payload inference. */
    public static function knownMissingImportedClockObservation(mixed $id, mixed $clock, mixed $payload): bool
    {
        return is_string($id) && $id !== '' && is_array($payload)
            && self::possibleMissingImportedClock($clock)
            && ($payload['event_id'] ?? null) === 'nflverse:'.$id
            && data_get($payload, 'market_context.source') === 'nflverse_schedules'
            && data_get($payload, 'market_context.normalization_version') === 'nflverse_schedule_v2'
            && array_key_exists('commence_time', $payload) && $payload['commence_time'] === null;
    }

    /** Only an exact zero clock representation is a candidate; metadata must still prove missingness. */
    public static function possibleMissingImportedClock(mixed $clock): bool
    {
        return $clock === null || $clock === '' || (is_string($clock) && preg_match('/\A00:00:00(?:\.0{1,6})?\z/', $clock) === 1);
    }

    public static function for(Game $game): ?CarbonImmutable
    {
        if (self::knownMissingImportedClock($game)) {
            return null;
        }
        $value = GameDateTimePresenter::utcStoredStartsAt('nfl', $game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'));

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }
}
