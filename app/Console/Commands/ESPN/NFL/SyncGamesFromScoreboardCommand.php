<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Jobs\ESPN\NFL\FetchGamesFromScoreboard;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncGamesFromScoreboardCommand extends Command
{
    protected $signature = 'espn:sync-nfl-games-scoreboard
                            {date? : The date in YYYYMMDD format (defaults to today)}
                            {--from-date= : Start date in YYYY-MM-DD format}
                            {--to-date= : End date in YYYY-MM-DD format}';

    protected $description = 'Sync NFL games from ESPN scoreboard API';

    public function handle(): int
    {
        // Handle date range
        if ($fromDate = $this->option('from-date')) {
            $toDate = $this->option('to-date') ?? date('Y-m-d');

            return $this->syncDateRange($fromDate, $toDate);
        }

        // Handle single date
        $date = $this->argument('date') ?? date('Ymd');

        $this->info("Dispatching NFL games scoreboard sync job for date {$date}...");

        FetchGamesFromScoreboard::dispatch($date);

        $this->info('NFL games scoreboard sync job dispatched successfully.');

        return Command::SUCCESS;
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
