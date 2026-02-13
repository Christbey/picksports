<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Jobs\ESPN\NFL\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-nfl-teams';

    protected $description = 'Sync NFL teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching NFL teams sync job...');

        FetchTeams::dispatch();

        $this->info('NFL teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
