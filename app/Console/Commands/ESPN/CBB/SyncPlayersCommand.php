<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Jobs\ESPN\CBB\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-cbb-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync CBB players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching CBB players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching CBB players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('CBB players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
