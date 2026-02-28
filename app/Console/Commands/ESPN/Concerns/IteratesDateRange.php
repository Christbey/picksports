<?php

namespace App\Console\Commands\ESPN\Concerns;

use Carbon\Carbon;

trait IteratesDateRange
{
    protected function inclusiveDayCount(Carbon $startDate, Carbon $endDate): int
    {
        return $startDate->diffInDays($endDate) + 1;
    }

    /**
     * Iterate each date in an inclusive range and execute callback.
     */
    protected function eachDateInRange(Carbon $startDate, Carbon $endDate, callable $callback): int
    {
        $totalDays = $this->inclusiveDayCount($startDate, $endDate);
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $callback($currentDate);
            $currentDate->addDay();
        }

        return $totalDays;
    }
}
