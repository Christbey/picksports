<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Jobs\ESPN\CFB\FetchTeams;
use Illuminate\Console\Command;

class SyncTeamsCommand extends Command
{
    protected $signature = 'espn:sync-cfb-teams';

    protected $description = 'Sync CFB teams from ESPN API';

    public function handle(): int
    {
        $this->info('Dispatching CFB teams sync job...');

        FetchTeams::dispatch();

        $this->info('CFB teams sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
