<?php

namespace App\Support\Sports;

use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\SportEvent;
use App\Support\CFB\CfbGameStart;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class CanonicalEventStart
{
    /** Callers requesting a write admission already own the event lock; never use a cached game relation. */
    public static function game(SportEvent $event, bool $current = false): ?Game
    {
        $games = $event->mlbGame()->orderBy('id')->when($current, fn ($query) => $query->lockForUpdate())->limit(2)->get();

        return $games->count() === 1 ? $games->first() : null;
    }

    public static function forEvent(SportEvent $event, bool $current = false): ?CarbonImmutable
    {
        if ($event->sport !== 'mlb') {
            return $event->starts_at?->toImmutable();
        }
        $game = self::game($event, $current);

        return $game === null ? null : MlbGameStart::canonical($game);
    }

    /** Current CFB admission only; historical generic forEvent() semantics remain unchanged. */
    public static function forCurrentCfbEvent(SportEvent $event, bool $current = false): ?CarbonImmutable
    {
        if ($event->sport !== 'cfb') {
            return null;
        }
        $connection = $event->getConnection();
        $bytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(kickoff_observation)' : 'length(CAST(kickoff_observation AS BLOB))';
        $games = $event->cfbGame()->useWritePdo()->select(['id', 'sport_event_id', 'espn_event_id', 'game_date', 'game_time'])
            ->selectRaw("CASE WHEN $bytes <= 1024 THEN kickoff_observation ELSE NULL END AS kickoff_observation")
            ->orderBy('id')->when($current, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        $start = $games->count() === 1 ? CfbGameStart::for($games->first()) : null;

        return $start?->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Pure conversion only; ownership/unique membership belongs to the explicit caller's fence. */
    public static function forOwnedMlbGame(SportEvent $event, Game $game): ?CarbonImmutable
    {
        return $event->sport === 'mlb' && (int) $game->sport_event_id === (int) $event->id
            ? MlbGameStart::canonical($game) : null;
    }

    public static function snapshot(SportEvent $event, ?EventInputSnapshot $input, bool $current = false): bool
    {
        if ($event->sport !== 'mlb') {
            return true;
        }
        $game = self::game($event, $current);

        return $game !== null && $input !== null && $input->sport === 'mlb'
            && (int) $input->sport_event_id === (int) $event->id && $input->phase === 'pregame'
            && self::snapshotForOwnedMlbGame($event, $input, $game, (array) $input->inputs);
    }

    /** SQL-free counterpart; never invokes the input accessor's reference resolver. */
    public static function snapshotForOwnedMlbGame(SportEvent $event, EventInputSnapshot $input, Game $game, array $inlineInputs): bool
    {
        if ($event->sport !== 'mlb' || (int) $game->sport_event_id !== (int) $event->id
            || $input->sport !== 'mlb' || (int) $input->sport_event_id !== (int) $event->id || $input->phase !== 'pregame'
            || (int) data_get($inlineInputs, 'event.game_id') !== (int) $game->id || MlbGameStart::canonical($game) === null
            || data_get($input->metadata, 'mlb_timing_policy') !== MlbGameStart::TIMING_POLICY
            || ! MlbGameStart::capturedFor($game, data_get($input->metadata, 'mlb_start_observation'))
            || $input->cutoff_at?->getTimestamp() !== MlbGameStart::for($game)?->getTimestamp()) {
            return false;
        }
        $capture = data_get($input->metadata, 'mlb_start_observation.captured_at_utc');

        return is_string($capture) && $input->captured_at?->getTimestamp() === CarbonImmutable::parse($capture)->getTimestamp();
    }

    public static function future(Builder $query, string $sport, CarbonInterface $now): Builder
    {
        return $sport === 'mlb'
            ? MlbGameStart::known($query, 'mlb_games')->where('mlb_games.start_epoch', '>', $now->getTimestamp())
            : $query->where('sport_events.starts_at', '>', $now);
    }
}
