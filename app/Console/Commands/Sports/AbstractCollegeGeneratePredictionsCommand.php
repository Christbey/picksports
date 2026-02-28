<?php

namespace App\Console\Commands\Sports;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

abstract class AbstractCollegeGeneratePredictionsCommand extends AbstractGeneratePredictionsCommand
{
    protected const TEAM_NAME_FIELDS = ['abbreviation'];

    protected const USES_EASTERN_DATE_WINDOW = false;

    protected function supportsWeekOption(): bool
    {
        return true;
    }

    protected function applyDateFilter(Builder $query, string $date): void
    {
        if (! $this->usesEasternDateWindow()) {
            parent::applyDateFilter($query, $date);

            return;
        }

        $etDate = Carbon::parse($date, 'America/New_York');
        $utcStart = $etDate->copy()->setTimezone('UTC');
        $utcEnd = $etDate->copy()->endOfDay()->setTimezone('UTC');

        $query->whereRaw(
            "datetime(date(game_date) || ' ' || game_time) >= ? AND datetime(date(game_date) || ' ' || game_time) <= ?",
            [$utcStart->toDateTimeString(), $utcEnd->toDateTimeString()]
        );
    }

    protected function usesEasternDateWindow(): bool
    {
        return static::USES_EASTERN_DATE_WINDOW;
    }

    protected function formatGameDate(mixed $game): string
    {
        return (string) $game->game_date;
    }

    protected function homeOffLabel(): string
    {
        return 'Home Off Rating';
    }

    protected function homeDefLabel(): string
    {
        return 'Home Def Rating';
    }

    protected function awayOffLabel(): string
    {
        return 'Away Off Rating';
    }

    protected function awayDefLabel(): string
    {
        return 'Away Def Rating';
    }
}
