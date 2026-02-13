<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Jobs\ESPN\CFB\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-cfb-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync CFB players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching CFB players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching CFB players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('CFB players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
