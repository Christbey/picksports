<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Jobs\ESPN\CFB\FetchGames;
use Illuminate\Console\Command;

class SyncGamesCommand extends Command
{
    protected $signature = 'espn:sync-cfb-games
                            {season : The season year}
                            {week : The week number}
                            {seasonType=2 : The season type (1=preseason, 2=regular, 3=postseason)}';

    protected $description = 'Sync CFB games from ESPN API for a specific week';

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $seasonType = (int) $this->argument('seasonType');
        $week = (int) $this->argument('week');

        $this->info("Dispatching CFB games sync job for Season {$season}, Week {$week}...");

        FetchGames::dispatch($season, $seasonType, $week);

        $this->info('CFB games sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
