<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Jobs\ESPN\MLB\FetchGames;
use Illuminate\Console\Command;

class SyncGamesCommand extends Command
{
    protected $signature = 'espn:sync-mlb-games
                            {season : The season year}
                            {week : The week number}
                            {seasonType=1 : The season type (1=regular, 3=postseason)}';

    protected $description = 'Sync MLB games from ESPN API for a specific week';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $seasonType = (int) $this->argument('seasonType');
        $week = (int) $this->argument('week');

        $this->info("Dispatching MLB games sync job for Season {$season}, Week {$week}...");

        FetchGames::dispatch($season, $seasonType, $week);

        $this->info('MLB games sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
