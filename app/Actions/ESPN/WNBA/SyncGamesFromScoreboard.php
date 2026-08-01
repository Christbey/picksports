<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\WNBA\UpdateLivePrediction;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;

    private const MINIMUM_FINAL_SYNC_MINUTES_AFTER_TIP = 90;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function guardFinalStatusForSync(array $attributes, ?Model $existingGame, string $source): array
    {
        if (($attributes['status'] ?? null) !== 'STATUS_FINAL') {
            return $attributes;
        }

        $scheduledStart = $this->scheduledStartAt($attributes, $existingGame);
        if ($scheduledStart === null) {
            return $attributes;
        }

        if (
            $this->hasFinalClock($attributes['game_clock'] ?? $existingGame?->game_clock)
            && CarbonImmutable::now('UTC')->gte($scheduledStart->addMinutes(self::MINIMUM_FINAL_SYNC_MINUTES_AFTER_TIP))
        ) {
            return $attributes;
        }

        $attributes['status'] = $existingGame?->status ?: 'STATUS_SCHEDULED';

        foreach (['home_score', 'away_score', 'home_linescores', 'away_linescores', 'period', 'game_clock'] as $key) {
            $attributes[$key] = $existingGame?->{$key};
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function scheduledStartAt(array $attributes, ?Model $existingGame): ?CarbonImmutable
    {
        $gameDate = $attributes['game_date'] ?? $existingGame?->game_date;
        $gameTime = $attributes['game_time'] ?? $existingGame?->game_time;

        if ($gameDate === null || $gameTime === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse((string) $gameDate, 'UTC')->toDateString();

            return CarbonImmutable::parse($date.' '.(string) $gameTime, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasFinalClock(mixed $clock): bool
    {
        $normalized = trim((string) $clock);

        return $normalized === ''
            || in_array($normalized, ['0', '0.0', '0:00', '00:00'], true);
    }
}
