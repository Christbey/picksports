<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Jobs\ESPN\CBB\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-cbb-teams';

    protected $description = 'Sync CBB teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching CBB teams sync job...');

        FetchTeams::dispatch();

        $this->info('CBB teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
