<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Jobs\ESPN\NBA\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-nba-teams';

    protected $description = 'Sync NBA teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching NBA teams sync job...');

        FetchTeams::dispatch();

        $this->info('NBA teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
