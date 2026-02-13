<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Jobs\ESPN\CBB\FetchPlays;
use Illuminate\Console\Command;

class SyncPlaysCommand extends Command
{
    protected $signature = 'espn:sync-cbb-plays
                            {eventId : The ESPN event/game ID}';

    protected $description = 'Sync CBB play-by-play data from ESPN API for a specific game';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        $this->info("Dispatching CBB plays sync job for event {$eventId}...");

        FetchPlays::dispatch($eventId);

        $this->info('CBB plays sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
