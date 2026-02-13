<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard;
use App\Jobs\ESPN\WCBB\FetchTeams;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncCurrentWeekCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-current
                            {--days-back=7 : Number of days to sync backwards from today}
                            {--days-forward=7 : Number of days to sync forward from today}';

    protected $description = 'Sync WCBB teams and games from the past and upcoming days';

    public function handle(): int
    {
        $this->info('Syncing WCBB teams...');
        FetchTeams::dispatch();

        $daysBack = (int) $this->option('days-back');
        $daysForward = (int) $this->option('days-forward');

        $startDate = Carbon::today()->subDays($daysBack);
        $endDate = Carbon::today()->addDays($daysForward);

        $totalDays = $startDate->diffInDays($endDate) + 1;

        $this->info("Syncing WCBB games from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$totalDays} days)...");

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            FetchGamesFromScoreboard::dispatch($currentDate->format('Ymd'));
            $currentDate->addDay();
        }

        $this->info("Dispatched {$totalDays} game sync jobs successfully.");

        return Command::SUCCESS;
    }
}
