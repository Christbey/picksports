<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchGames;
use Illuminate\Console\Command;

class SyncGamesCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-games
                            {season : The season year}
                            {--from-week=1 : Starting week number}
                            {--to-week= : Ending week number (defaults to from-week for single week)}
                            {--season-type=2 : The season type (1=preseason, 2=regular, 3=postseason)}';

    protected $description = 'Sync WCBB games from ESPN API for a week range';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $seasonType = (int) $this->option('season-type');
        $fromWeek = (int) $this->option('from-week');
        $toWeek = $this->option('to-week') ? (int) $this->option('to-week') : $fromWeek;

        if ($fromWeek > $toWeek) {
            $this->error('from-week cannot be greater than to-week');

            return Command::FAILURE;
        }

        $totalWeeks = $toWeek - $fromWeek + 1;

        $this->info("Dispatching WCBB games sync jobs for Season {$season}, Weeks {$fromWeek}-{$toWeek} ({$totalWeeks} weeks)...");

        for ($week = $fromWeek; $week <= $toWeek; $week++) {
            FetchGames::dispatch($season, $seasonType, $week);
        }

        $this->info("Dispatched {$totalWeeks} WCBB games sync jobs successfully.");

        return Command::SUCCESS;
    }
}
