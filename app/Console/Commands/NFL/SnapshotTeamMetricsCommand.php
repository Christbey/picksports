<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\TeamMetricSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SnapshotTeamMetricsCommand extends Command
{
    protected $signature = 'nfl:snapshot-team-metrics
        {--season= : Season to snapshot}
        {--date=* : Optional snapshot timestamp(s) (defaults to now)}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--daily : Expand from/to into one snapshot per day}
        {--hour=12 : UTC hour to use for daily snapshots}
        {--backfill-records : Backfill wins/losses from completed games for each date}';

    protected $description = 'Capture a point-in-time snapshot of NFL team metrics for futures backtesting';

    public function handle(TeamMetricSnapshotService $snapshotService): int
    {
        $season = (int) ($this->option('season') ?: date('Y'));
        $dates = $this->resolvedDates();

        if ($dates === []) {
            $count = $snapshotService->capture($season);

            $this->info("Captured {$count} NFL team metric snapshots for season {$season}.");

            return self::SUCCESS;
        }

        if ((bool) $this->option('backfill-records')) {
            $count = $snapshotService->backfill($season, $dates);
            $this->info("Backfilled {$count} NFL team metric snapshots for season {$season}.");

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($dates as $date) {
            $count += $snapshotService->capture($season, $date);
        }

        $this->info("Captured {$count} NFL team metric snapshots for season {$season}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolvedDates(): array
    {
        $dates = $this->option('date');
        if (is_string($dates) && trim($dates) !== '') {
            return [
                Carbon::parse($dates)->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        if (is_array($dates) && $dates !== []) {
            return array_values(array_unique(array_map(
                static fn ($date) => Carbon::parse((string) $date)->utc()->format('Y-m-d\TH:i:s\Z'),
                $dates
            )));
        }

        if (! $this->option('daily')) {
            return [];
        }

        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        if (! is_string($fromDate) || ! is_string($toDate) || trim($fromDate) === '' || trim($toDate) === '') {
            return [];
        }

        $hour = max(0, min(23, (int) $this->option('hour')));
        $cursor = Carbon::parse($fromDate, 'UTC')->startOfDay()->setHour($hour);
        $end = Carbon::parse($toDate, 'UTC')->startOfDay()->setHour($hour);
        $resolved = [];

        while ($cursor->lte($end)) {
            $resolved[] = $cursor->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $cursor->addDay();
        }

        return $resolved;
    }
}
