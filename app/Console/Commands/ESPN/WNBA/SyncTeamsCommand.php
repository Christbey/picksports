<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Jobs\ESPN\WNBA\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-wnba-teams';

    protected $description = 'Sync WNBA teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching WNBA teams sync job...');

        FetchTeams::dispatch();

        $this->info('WNBA teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
