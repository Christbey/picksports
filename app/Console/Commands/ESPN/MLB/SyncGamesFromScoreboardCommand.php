<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Jobs\ESPN\MLB\FetchGamesFromScoreboard;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncGamesFromScoreboardCommand extends Command
{
    protected $signature = 'espn:sync-mlb-games-scoreboard
                            {date? : The date in YYYYMMDD format (defaults to today)}
                            {--from-date= : Start date in YYYY-MM-DD format}
                            {--to-date= : End date in YYYY-MM-DD format}
                            {--season= : Sync entire season (e.g., 2026 syncs Apr - Oct 2026)}';

    protected $description = 'Sync MLB games from ESPN scoreboard API';

    public function handle(): int
    {
        // Handle season option
        if ($season = $this->option('season')) {
            return $this->syncSeason($season);
        }

        // Handle date range
        if ($fromDate = $this->option('from-date')) {
            $toDate = $this->option('to-date') ?? date('Y-m-d');

            return $this->syncDateRange($fromDate, $toDate);
        }

        // Handle single date
        $date = $this->argument('date') ?? date('Ymd');

        $this->info("Dispatching MLB games scoreboard sync job for date {$date}...");

        FetchGamesFromScoreboard::dispatch($date);

        $this->info('MLB games scoreboard sync job dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function syncSeason(int $season): int
    {
        // MLB season runs from April to October
        $startDate = Carbon::create($season, 4, 1)->startOfMonth();
        $endDate = Carbon::create($season, 10, 31)->endOfMonth();

        $this->info("Syncing full {$season} MLB season ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})...");

        if ($endDate->isFuture()) {
            $this->info('Note: Including future dates to capture scheduled games.');
        }

        return $this->syncDateRange($startDate->format('Y-m-d'), $endDate->format('Y-m-d'));
    }

    protected function syncDateRange(string $fromDate, string $toDate): int
    {
        $startDate = Carbon::parse($fromDate);
        $endDate = Carbon::parse($toDate);

        $totalDays = $startDate->diffInDays($endDate) + 1;

        $this->info("Queuing {$totalDays} days of games ({$fromDate} to {$toDate})...");

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            FetchGamesFromScoreboard::dispatch($currentDate->format('Ymd'));
            $bar->advance();
            $currentDate->addDay();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Queued {$totalDays} game sync jobs successfully.");
        $this->info('Run "php artisan queue:work" to process the jobs.');

        return Command::SUCCESS;
    }
}
