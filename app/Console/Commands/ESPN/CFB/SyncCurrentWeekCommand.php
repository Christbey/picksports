<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Jobs\ESPN\CFB\FetchGames;
use App\Jobs\ESPN\CFB\FetchTeams;
use Illuminate\Console\Command;

class SyncCurrentWeekCommand extends Command
{
    protected $signature = 'espn:sync-cfb-current';

    protected $description = 'Sync CFB teams and current week games from ESPN API';

    public function handle(): int
    {
        $this->info('Syncing CFB teams...');
        FetchTeams::dispatch();

        $currentYear = (int) date('Y');
        $currentWeek = $this->getCurrentWeek();

        $this->info("Syncing CFB games for Week {$currentWeek}...");
        FetchGames::dispatch($currentYear, 2, $currentWeek);

        $this->info('Sync jobs dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function getCurrentWeek(): int
    {
        // Simple logic: estimate based on date
        // CFB season typically starts last week of August
        $now = now();
        $seasonStart = now()->setMonth(8)->setDay(24);

        if ($now->lessThan($seasonStart)) {
            return 1;
        }

        $weekNumber = $now->diffInWeeks($seasonStart) + 1;

        return min($weekNumber, 15); // Regular season is typically 12-15 weeks
    }
}
