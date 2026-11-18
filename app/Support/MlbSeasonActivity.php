<?php

namespace App\Support;

use App\Models\MLB\Game;

final class MlbSeasonActivity
{
    public static function isActive(): bool
    {
        $today = now(config('sports.business_timezone', config('app.timezone')));
        if (! in_array($today->month, (array) config('healthchecks.season_months.mlb', []), true)) {
            return false;
        }
        if ($today->month !== 11) {
            return true;
        }

        return Game::query()
            ->where('season', $today->year)
            ->whereIn('season_type', [config('mlb.season.types.postseason'), 'postseason', 'Post Season'])
            ->whereBetween('game_date', [$today->copy()->subDays(2)->toDateString(), $today->copy()->addDays(3)->toDateString()])
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_DELAYED', 'STATUS_FINAL'])
            ->exists();
    }
}
