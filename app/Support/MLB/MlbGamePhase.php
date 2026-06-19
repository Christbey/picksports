<?php

namespace App\Support\MLB;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MlbGamePhase
{
    public const PREGAME = 'pregame';

    public const DELAYED = 'delayed';

    public const LIVE = 'live';

    public const FINAL = 'final';

    public const POSTPONED = 'postponed';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const UNKNOWN = 'unknown';

    public static function phase(Model|string|null $gameOrStatus): string
    {
        $status = $gameOrStatus instanceof Model
            ? (string) ($gameOrStatus->getAttribute('status') ?? '')
            : (string) ($gameOrStatus ?? '');

        return match ($status) {
            config('mlb.statuses.scheduled'), 'STATUS_SCHEDULED' => self::PREGAME,
            config('mlb.statuses.delayed'), 'STATUS_DELAYED' => self::DELAYED,
            config('mlb.statuses.in_progress'), 'STATUS_IN_PROGRESS' => self::LIVE,
            config('mlb.statuses.final'), 'STATUS_FINAL' => self::FINAL,
            config('mlb.statuses.postponed'), 'STATUS_POSTPONED' => self::POSTPONED,
            config('mlb.statuses.suspended'), 'STATUS_SUSPENDED' => self::SUSPENDED,
            config('mlb.statuses.canceled'), 'STATUS_CANCELED', 'STATUS_CANCELLED' => self::CANCELLED,
            default => self::UNKNOWN,
        };
    }

    public static function isPregame(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::PREGAME;
    }

    public static function isDelayed(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::DELAYED;
    }

    public static function isLive(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::LIVE;
    }

    public static function isFinal(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::FINAL;
    }

    public static function isPostponed(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::POSTPONED;
    }

    public static function isSuspended(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::SUSPENDED;
    }

    public static function isCancelled(Model|string|null $gameOrStatus): bool
    {
        return self::phase($gameOrStatus) === self::CANCELLED;
    }

    public static function isBacktestEligiblePregame(Model $game, ?CarbonInterface $predictionTimestamp): bool
    {
        if (in_array(self::phase($game), [self::POSTPONED, self::SUSPENDED, self::CANCELLED, self::UNKNOWN], true)) {
            return false;
        }

        $startAt = self::scheduledStartAt($game);

        return $predictionTimestamp !== null
            && $startAt !== null
            && Carbon::parse($predictionTimestamp)->lt($startAt);
    }

    public static function scheduledStartAt(Model $game): ?Carbon
    {
        $gameDate = $game->getAttribute('game_date');
        $gameTime = $game->getAttribute('game_time');

        if ($gameDate === null || $gameTime === null) {
            return null;
        }

        $date = $gameDate instanceof CarbonInterface
            ? $gameDate->toDateString()
            : Carbon::parse((string) $gameDate)->toDateString();

        return Carbon::parse(sprintf('%s %s', $date, (string) $gameTime), (string) config('app.timezone', 'UTC'));
    }
}
