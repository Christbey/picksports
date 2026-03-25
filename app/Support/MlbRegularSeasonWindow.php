<?php

namespace App\Support;

use App\Models\MLB\Game;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class MlbRegularSeasonWindow
{
    public static function openerDate(int $season): ?string
    {
        $date = Game::query()
            ->where('season', $season)
            ->whereNotNull('game_date')
            ->where(function (Builder $query) {
                $query->whereNull('week')
                    ->orWhere('week', '!=', 1);
            })
            ->orderBy('game_date')
            ->value('game_date');

        return self::normalizeDate($date);
    }

    public static function hasCompletedGamesBefore(Game $game): bool
    {
        $query = Game::query()
            ->where('season', $game->season)
            ->where('status', config('mlb.statuses.final'))
            ->whereDate('game_date', '<', self::normalizeDate($game->game_date));

        if ($openerDate = self::openerDate((int) $game->season)) {
            $query->whereDate('game_date', '>=', $openerDate);
        }

        return $query->exists();
    }

    public static function applyCarryoverFilter(
        Builder $query,
        int $season,
        string $seasonColumn = 'season',
        string $dateColumn = 'date',
        ?string $beforeDate = null
    ): Builder {
        if ($beforeDate !== null) {
            $query->whereDate($dateColumn, '<', self::normalizeDate($beforeDate));
        }

        $openerDate = self::openerDate($season);

        return $query->where(function (Builder $seasonQuery) use ($season, $seasonColumn, $dateColumn, $openerDate) {
            $seasonQuery->where($seasonColumn, '<', $season)
                ->orWhere(function (Builder $currentSeasonQuery) use ($season, $seasonColumn, $dateColumn, $openerDate) {
                    $currentSeasonQuery->where($seasonColumn, $season);

                    if ($openerDate !== null) {
                        $currentSeasonQuery->whereDate($dateColumn, '>=', $openerDate);
                    }
                });
        });
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return date('Y-m-d', strtotime((string) $value));
    }
}
