<?php

namespace App\Support\MLB;

use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MlbGameStart
{
    public const VERSION = 1;

    public const TIMING_POLICY = 'mlb-observed-start-v1';

    public const FIELDS = ['start_time_version', 'start_observation_id', 'start_epoch', 'start_source_hash', 'start_context_hash', 'start_observed_at'];

    /** Strict provider-observed instant. Precision is whole seconds; fractional seconds are discarded. */
    public static function observed(string $value, array $raw): array
    {
        // ESPN also supplies explicit offset/Z minute-precision ISO timestamps.
        if (preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/D', $value, $parts) === 1) {
            $value = $parts[1].':00'.$parts[2];
        }
        if (strlen($value) > 64 || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/D', $value) !== 1) {
            throw new InvalidArgumentException('MLB start requires an explicit valid offset/Z calendar timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException('MLB start contains an invalid calendar date/time.');
        }

        return [
            'start_time_version' => self::VERSION,
            'start_epoch' => $date->getTimestamp(),
            // Final source-fact digest is computed from resolved identity in prepare(), not supplied metadata.
            'start_observed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ];
    }

    /** Complete a new observation only after actual team-slot/identity resolution. Missing observations preserve facts. */
    public static function prepare(Game $game, array $attributes): array
    {
        foreach (['game_date', 'game_time'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] === null) {
                unset($attributes[$field]);
            }
        }
        if (! $game->exists && (! array_key_exists('start_epoch', $attributes) || empty($attributes['game_date']) || empty($attributes['game_time']))) {
            throw new InvalidArgumentException('A newly ingested MLB game requires an observed explicit scheduled instant.');
        }
        if (array_key_exists('start_epoch', $attributes)) {
            $copy = clone $game;
            $copy->forceFill($attributes);
            $attributes['start_source_hash'] = self::sourceFact($copy);
            $equivalent = $game->exists && self::for($game) !== null && self::contextValues($copy) === self::contextValues($game)
                && $attributes['start_source_hash'] === $game->start_source_hash;
            $attributes['start_observation_id'] = $equivalent ? $game->start_observation_id : (string) Str::uuid();
            $attributes['start_observed_at'] = $equivalent ? $game->start_observed_at : $attributes['start_observed_at'];
            $copy->forceFill($attributes);
            $attributes['start_context_hash'] = self::context($copy);
        }

        return $attributes;
    }

    public static function for(Game $game): ?Carbon
    {
        try {
            return self::read($game);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function status(Game $game): string
    {
        if (self::for($game) !== null) {
            return 'observed_offset_timestamp_v1';
        }
        foreach (self::FIELDS as $field) {
            if ($game->getAttribute($field) !== null) {
                return 'observed_start_invalid';
            }
        }

        return 'legacy_time_unverified';
    }

    private static function read(Game $game): ?Carbon
    {
        $epoch = $game->getAttribute('start_epoch');
        $observed = $game->getAttribute('start_observed_at');
        $stamp = is_string($observed) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $observed, new \DateTimeZone('UTC')) : false;
        $stampErrors = DateTimeImmutable::getLastErrors();
        if ($stamp === false || ($stampErrors !== false && ($stampErrors['error_count'] || $stampErrors['warning_count'])) || (int) $epoch < -62167219200 || (int) $epoch > 253402300799
            || ! is_string($game->start_observation_id) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $game->start_observation_id) !== 1
            || empty($game->espn_event_id) || (int) $game->home_team_id < 1 || (int) $game->away_team_id < 1 || (int) $game->home_team_id === (int) $game->away_team_id
            || ! in_array($game->getAttribute('start_time_version'), [self::VERSION, (string) self::VERSION], true)
            || ! (is_int($epoch) || (is_string($epoch) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $epoch) === 1 && (string) (int) $epoch === $epoch))
            || ! is_string($game->getAttribute('start_source_hash'))
            || preg_match('/\A[a-f0-9]{64}\z/D', $game->start_source_hash) !== 1
            || ! hash_equals(self::sourceFact($game), $game->start_source_hash)
            || ! is_string($game->getAttribute('start_context_hash'))
            || ! hash_equals(self::context($game), $game->start_context_hash)
            || ! is_string($game->getRawOriginal('start_observed_at') ?? $game->getAttribute('start_observed_at'))
        ) {
            return null;
        }

        return Carbon::createFromTimestampUTC((int) $game->start_epoch);
    }

    /** Legacy canonical timestamps cannot encode both repeated wall-clock hours. Decline a lossy adapter. */
    public static function canonical(Game $game): ?CarbonImmutable
    {
        $instant = self::for($game)?->toImmutable();
        if ($instant === null) {
            return null;
        }

        return self::legacyTimestamp($instant);
    }

    public static function legacyTimestamp(CarbonInterface $value): ?CarbonImmutable
    {
        $instant = CarbonImmutable::instance($value);
        $timezone = (string) config('app.timezone', 'UTC');
        $wall = $instant->setTimezone($timezone);
        $wallLiteral = CarbonImmutable::parse($wall->format('Y-m-d H:i:s'), 'UTC')->getTimestamp();
        if ($instant->getTimestamp() < 1 || $instant->getTimestamp() > 2147483647 || $wallLiteral < 1 || $wallLiteral > 2147483647) {
            return null;
        }
        $roundTrip = CarbonImmutable::parse($wall->format('Y-m-d H:i:s'), $timezone);

        return $roundTrip->getTimestamp() === $instant->getTimestamp() ? $wall : null;
    }

    public static function artifactCompatible(?ModelArtifact $artifact): bool
    {
        $manifest = $artifact?->trainingRun?->metadata['python_manifest'] ?? null;

        return is_array($manifest) && ($manifest['timing_policy'] ?? null) === self::TIMING_POLICY
            && ($manifest['source_availability_verified'] ?? null) === true;
    }

    public static function capture(Game $game, CarbonInterface $capturedAt): array
    {
        return ['version' => self::VERSION, 'epoch' => $game->start_epoch, 'context_hash' => $game->start_context_hash,
            'source_hash' => $game->start_source_hash, 'observed_at_utc' => $game->start_observed_at,
            'captured_at_utc' => $capturedAt->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    public static function capturedFor(Game $game, mixed $capture): bool
    {
        try {
            if (! is_array($capture) || ! is_string($capture['captured_at_utc'] ?? null)) {
                return false;
            }
            $capturedAt = CarbonImmutable::parse($capture['captured_at_utc'], 'UTC');
            $expected = self::capture($game, $capturedAt);
            ksort($capture);
            ksort($expected);

            return $capture === $expected && self::observedBy($game, $capturedAt)
                && $capturedAt->lt(self::for($game));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function observedBy(Game $game, CarbonInterface $capture): bool
    {
        if (self::for($game) === null) {
            return false;
        }

        return CarbonImmutable::parse((string) $game->getAttribute('start_observed_at'), 'UTC')->lte($capture);
    }

    private static function sourceFact(Game $game): string
    {
        return hash('sha256', implode('|', ['espn', hash('sha256', (string) $game->espn_event_id), (int) $game->start_epoch]));
    }

    public static function context(Game $game): string
    {
        $values = self::contextValues($game);

        return hash('sha256', implode('|', [hash('sha256', $values['espn_event_id']), $values['home_team_id'], $values['away_team_id'], $values['game_date'], $values['game_time'], $values['epoch'], $game->start_observed_at, $game->start_observation_id]));
    }

    private static function contextValues(Game $game): array
    {
        $date = $game->getAttribute('game_date');

        return [
            'espn_event_id' => (string) $game->getAttribute('espn_event_id'),
            'home_team_id' => (int) $game->getAttribute('home_team_id'),
            'away_team_id' => (int) $game->getAttribute('away_team_id'),
            'game_date' => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date,
            'game_time' => $game->getAttribute('game_time'),
            'epoch' => (int) $game->getAttribute('start_epoch'),
        ];
    }

    /** SQL mirrors bound identity values; hydrated readers additionally verify the canonical digest. */
    public static function known(Builder $query, ?string $table = null): Builder
    {
        $table ??= $query->getModel()->getTable();
        $grammar = $query->getQuery()->getGrammar();
        $query->where("{$table}.start_time_version", self::VERSION)
            ->whereNotNull("{$table}.start_observed_at")
            ->whereNotNull("{$table}.start_source_hash")->whereNotNull("{$table}.start_context_hash");
        $columns = array_map(fn (string $field): string => $grammar->wrap("{$table}.{$field}"), ['espn_event_id', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'start_epoch', 'start_observed_at', 'start_observation_id']);
        [$provider, $home, $away, $day, $time, $epoch, $observed, $observationId] = $columns;
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $query->getConnection()->getPdo()->sqliteCreateFunction('mlb_sha256', static fn ($value): string => hash('sha256', (string) $value), 1, \PDO::SQLITE_DETERMINISTIC);
            $query->getConnection()->getPdo()->sqliteCreateFunction('mlb_uuid4', static fn ($value): int => is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1 ? 1 : 0, 1, \PDO::SQLITE_DETERMINISTIC);
            $query->whereRaw("mlb_uuid4({$observationId}) = 1");
            $query->whereRaw("datetime({$observed}) is not null and length({$observed}) = 26 and {$observed} glob '????-??-?? ??:??:??.??????'");
            $query->whereRaw($grammar->wrap("{$table}.start_source_hash")." not glob '*[^0-9a-f]*'")->whereRaw('length('.$grammar->wrap("{$table}.start_source_hash").') = 64');
            $sourceDigest = "mlb_sha256('espn|' || mlb_sha256({$provider}) || '|' || {$epoch})";
            $digest = "mlb_sha256(mlb_sha256({$provider}) || '|' || {$home} || '|' || {$away} || '|' || date({$day}) || '|' || {$time} || '|' || {$epoch} || '|' || {$observed} || '|' || {$observationId})";
        } elseif ($query->getConnection()->getDriverName() === 'mysql') {
            $sourceDigest = "sha2(concat('espn|', sha2({$provider}, 256), '|', {$epoch}), 256)";
            $digest = "sha2(concat(sha2({$provider}, 256), '|', {$home}, '|', {$away}, '|', date({$day}), '|', {$time}, '|', {$epoch}, '|', date_format({$observed}, '%Y-%m-%d %H:%i:%s.%f'), '|', {$observationId}), 256)";
        } else {
            return $query->whereRaw('1 = 0');
        }

        $sourceColumn = $grammar->wrap("{$table}.start_source_hash");
        $hashColumn = $grammar->wrap("{$table}.start_context_hash");
        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->whereRaw("regexp_like({$observationId}, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')");
            $query->whereRaw('regexp_like('.$grammar->wrap("{$table}.start_source_hash").", '^[0-9a-f]{64}$', 'c')");
            $hashColumn = 'BINARY '.$hashColumn;
            $sourceColumn = 'BINARY '.$sourceColumn;
        }

        return $query->whereRaw($sourceColumn." = {$sourceDigest}")->where("{$table}.espn_event_id", '!=', '')->where("{$table}.home_team_id", '>', 0)->where("{$table}.away_team_id", '>', 0)->whereColumn("{$table}.home_team_id", '!=', "{$table}.away_team_id")->whereRaw($hashColumn." = {$digest}");
    }

    public static function before(Builder $query, ?CarbonInterface $cutoff): Builder
    {
        return $cutoff === null ? $query->whereRaw('1 = 0') : self::known($query)
            ->where($query->getModel()->qualifyColumn('start_epoch'), '<', $cutoff->getTimestamp());
    }
}
