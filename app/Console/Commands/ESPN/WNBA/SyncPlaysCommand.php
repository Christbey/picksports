<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Jobs\ESPN\WNBA\FetchPlays;
use Illuminate\Console\Command;

class SyncPlaysCommand extends Command
{
    protected $signature = 'espn:sync-wnba-plays
                            {eventId : The ESPN event/game ID}';

    protected $description = 'Sync WNBA play-by-play data from ESPN API for a specific game';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        $this->info("Dispatching WNBA plays sync job for event {$eventId}...");

        FetchPlays::dispatch($eventId);

        $this->info('WNBA plays sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
