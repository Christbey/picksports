<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Jobs\ESPN\NFL\FetchGames;
use Illuminate\Console\Command;

class SyncGamesCommand extends Command
{
    protected $signature = 'espn:sync-nfl-games
                            {season : The season year}
                            {week : The week number}
                            {seasonType=2 : The season type (1=preseason, 2=regular, 3=postseason)}';

    protected $description = 'Sync NFL games from ESPN API for a specific week';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $seasonType = (int) $this->argument('seasonType');
        $week = (int) $this->argument('week');

        $this->info("Dispatching NFL games sync job for Season {$season}, Week {$week}...");

        FetchGames::dispatch($season, $seasonType, $week);

        $this->info('NFL games sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
