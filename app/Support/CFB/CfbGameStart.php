<?php

namespace App\Support\CFB;

use App\Models\CFB\Game;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** CFB-only current kickoff evidence. Legacy carriers are not proof of a known instant. */
final class CfbGameStart
{
    public const SOURCES = ['espn_schedule', 'espn_scoreboard', 'espn_verified_summary'];

    public static function observation(mixed $date, array $competition, string $source, string $eventId): array
    {
        $dateValid = $competition['dateValid'] ?? null;
        $timeValid = $competition['timeValid'] ?? null;
        $flagsValid = (! array_key_exists('dateValid', $competition) || is_bool($dateValid))
            && (! array_key_exists('timeValid', $competition) || is_bool($timeValid));
        $detail = data_get($competition, 'status.type.detail');
        $short = data_get($competition, 'status.type.shortDetail');
        $detailsValid = ($detail === null || is_string($detail)) && ($short === null || is_string($short));
        $detail = $detailsValid ? ($detail ?? '').' '.($short ?? '') : '';
        $tbd = ! $detailsValid || strlen($detail) > 256 || preg_match('/\b(?:TBD|TBA)\b/i', $detail) === 1;
        $raw = is_string($date) && strlen($date) <= 64 ? $date : null;
        $known = $flagsValid && $dateValid !== false && $timeValid !== false && ! $tbd && self::parse($raw) !== null;

        return ['version' => 1, 'source' => $source, 'event_id' => strlen($eventId) <= 64 ? $eventId : null,
            'raw_date' => $raw, 'date_valid' => is_bool($dateValid) ? $dateValid : null,
            'time_valid' => is_bool($timeValid) ? $timeValid : null, 'time_tbd' => $tbd,
            'status' => $known ? 'known' : 'unconfirmed'];
    }

    public static function for(Game $game): ?CarbonImmutable
    {
        $raw = $game->getAttributes()['kickoff_observation'] ?? null;
        if (! is_string($raw) || strlen($raw) > 1024) {
            return null;
        }
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($value) || count($value) !== 8 || ($value['version'] ?? null) !== 1
            || ! in_array($value['source'] ?? null, self::SOURCES, true) || ($value['status'] ?? null) !== 'known'
            || ! is_string($value['event_id'] ?? null) || $value['event_id'] === '' || strlen($value['event_id']) > 64
            || $value['event_id'] !== (string) $game->espn_event_id
            || ! array_key_exists('date_valid', $value) || ! array_key_exists('time_valid', $value)
            || ! in_array($value['date_valid'], [true, null], true) || ! in_array($value['time_valid'], [true, null], true)
            || ($value['time_tbd'] ?? null) !== false || ! ($instant = self::parse($value['raw_date'] ?? null))) {
            return null;
        }
        $date = substr((string) ($game->getAttributes()['game_date'] ?? ''), 0, 10);
        $clock = $game->getAttributes()['game_time'] ?? null;
        if ($date !== $instant->toDateString() || ! is_string($clock) || $clock !== $instant->format('H:i:s')) {
            return null;
        }

        return $instant;
    }

    public static function status(Game $game): string
    {
        if (self::for($game) !== null) {
            return 'provider_clock_known_v1';
        }

        return (int) ($game->getAttributes()['read_clock_metadata_unavailable'] ?? 0) === 1 ? 'clock_unconfirmed_or_invalid'
            : (($game->getAttributes()['kickoff_observation'] ?? null) === null ? 'legacy_clock_unverified' : 'clock_unconfirmed_or_invalid');
    }

    /** Unknown observations retain carriers only for schema/calendar compatibility, never as an instant. */
    public static function attributes(mixed $date, array $competition, string $source, string $eventId): array
    {
        $observation = self::observation($date, $competition, $source, $eventId);
        $attributes = ['kickoff_observation' => $observation];
        if ($observation['status'] === 'known') {
            $instant = self::parse($date);
            $attributes += ['game_date' => $instant->toDateString(), 'game_time' => $instant->format('H:i:s')];
        }

        return $attributes;
    }

    private static function parse(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || strlen($date) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/D', $date) !== 1) {
            return null;
        }
        $date = preg_replace('/(T\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})$/D', '$1:00$2', $date);
        $parsed = DateTimeImmutable::createFromFormat(str_contains($date, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        return CarbonImmutable::instance($parsed)->utc()->setMicrosecond(0);
    }
}
