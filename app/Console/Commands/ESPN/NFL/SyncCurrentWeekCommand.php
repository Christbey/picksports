<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Jobs\ESPN\NFL\FetchGames;
use App\Jobs\ESPN\NFL\FetchTeams;
use Illuminate\Console\Command;

class SyncCurrentWeekCommand extends Command
{
    protected $signature = 'espn:sync-nfl-current';

    protected $description = 'Sync NFL teams and current week games from ESPN API';

    public function handle(): int
    {
        $this->info('Syncing NFL teams...');
        FetchTeams::dispatch();

        $currentYear = (int) date('Y');
        $currentWeek = $this->getCurrentWeek();

        $this->info("Syncing NFL games for Week {$currentWeek}...");
        FetchGames::dispatch($currentYear, 2, $currentWeek);

        $this->info('Sync jobs dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function getCurrentWeek(): int
    {
        // Simple logic: estimate based on date
        // NFL season typically starts first week of September
        $now = now();
        $seasonStart = now()->setMonth(9)->setDay(1);

        if ($now->lessThan($seasonStart)) {
            return 1;
        }

        $weekNumber = $now->diffInWeeks($seasonStart) + 1;

        return min($weekNumber, 18); // Regular season is 18 weeks
    }
}
