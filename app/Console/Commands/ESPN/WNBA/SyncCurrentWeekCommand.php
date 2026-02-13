<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Jobs\ESPN\WNBA\FetchGames;
use App\Jobs\ESPN\WNBA\FetchTeams;
use Illuminate\Console\Command;

class SyncCurrentWeekCommand extends Command
{
    protected $signature = 'espn:sync-wnba-current';

    protected $description = 'Sync WNBA teams and current week games from ESPN API';

    public function handle(): int
    {
        $this->info('Syncing WNBA teams...');
        FetchTeams::dispatch();

        $currentYear = (int) date('Y');
        $currentWeek = $this->getCurrentWeek();

        $this->info("Syncing WNBA games for Week {$currentWeek}...");
        FetchGames::dispatch($currentYear, 2, $currentWeek);

        $this->info('Sync jobs dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function getCurrentWeek(): int
    {
        // Simple logic: estimate based on date
        // WNBA season typically starts second week of May
        $now = now();
        $seasonStart = now()->setMonth(5)->setDay(8);

        if ($now->lessThan($seasonStart)) {
            return 1;
        }

        $weekNumber = $now->diffInWeeks($seasonStart) + 1;

        return min($weekNumber, 15); // Regular season is approximately 15 weeks
    }
}
