<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Jobs\ESPN\MLB\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-mlb-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync MLB players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching MLB players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching MLB players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('MLB players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
