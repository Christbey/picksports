<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchPlays;
use Illuminate\Console\Command;

class SyncPlaysCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-plays
                            {eventId : The ESPN event/game ID}';

    protected $description = 'Sync WCBB play-by-play data from ESPN API for a specific game';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        $this->info("Dispatching WCBB plays sync job for event {$eventId}...");

        FetchPlays::dispatch($eventId);

        $this->info('WCBB plays sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
