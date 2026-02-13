<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Jobs\ESPN\CFB\FetchPlays;
use Illuminate\Console\Command;

class SyncPlaysCommand extends Command
{
    protected $signature = 'espn:sync-cfb-plays
                            {eventId : The ESPN event/game ID}';

    protected $description = 'Sync CFB play-by-play data from ESPN API for a specific game';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        $this->info("Dispatching CFB plays sync job for event {$eventId}...");

        FetchPlays::dispatch($eventId);

        $this->info('CFB plays sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
