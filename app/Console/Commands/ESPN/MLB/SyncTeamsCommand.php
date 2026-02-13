<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Jobs\ESPN\MLB\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-mlb-teams';

    protected $description = 'Sync MLB teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching MLB teams sync job...');

        FetchTeams::dispatch();

        $this->info('MLB teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
