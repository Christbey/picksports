<?php

namespace App\Console\Commands\ESPN;

use Carbon\Carbon;

abstract class AbstractSeasonalSyncGamesFromScoreboardCommand extends AbstractSyncGamesFromScoreboardCommand
{
    protected const SEASON_START_MONTH = 1;

    protected const SEASON_END_MONTH = 12;

    protected function seasonOptionSegment(): string
    {
        return sprintf(
            '--season= : Sync entire season (e.g., %s)',
            $this->seasonSyncExample()
        );
    }

    protected function seasonSyncExample(): string
    {
        $season = (int) date('Y');
        [$startDate, $endDate] = $this->seasonDateRange($season);

        return sprintf(
            '%d syncs %s %d - %s %d',
            $season,
            $startDate->format('M'),
            (int) $startDate->format('Y'),
            $endDate->format('M'),
            (int) $endDate->format('Y')
        );
    }

    protected function supportsSeasonSync(): bool
    {
        return true;
    }

    protected function seasonDateRange(int $season): array
    {
        $startYear = $season + $this->seasonStartYearOffset();

        return [
            Carbon::create($startYear, $this->seasonStartMonth(), 1)->startOfMonth(),
            Carbon::create($season, $this->seasonEndMonth(), 1)->endOfMonth(),
        ];
    }

    protected function seasonStartYearOffset(): int
    {
        return $this->seasonCrossesYearBoundary() ? -1 : 0;
    }

    protected function seasonCrossesYearBoundary(): bool
    {
        return $this->seasonStartMonth() > $this->seasonEndMonth();
    }

    protected function seasonStartMonth(): int
    {
        return static::SEASON_START_MONTH;
    }

    protected function seasonEndMonth(): int
    {
        return static::SEASON_END_MONTH;
    }
}
