<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Jobs\ESPN\NFL\FetchPlays;
use Illuminate\Console\Command;

class SyncPlaysCommand extends Command
{
    protected $signature = 'espn:sync-nfl-plays
                            {eventId : The ESPN event/game ID}';

    protected $description = 'Sync NFL play-by-play data from ESPN API for a specific game';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        $this->info("Dispatching NFL plays sync job for event {$eventId}...");

        FetchPlays::dispatch($eventId);

        $this->info('NFL plays sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
