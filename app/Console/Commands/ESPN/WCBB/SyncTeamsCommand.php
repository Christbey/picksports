<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-teams';

    protected $description = 'Sync WCBB teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching WCBB teams sync job...');

        FetchTeams::dispatch();

        $this->info('WCBB teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
