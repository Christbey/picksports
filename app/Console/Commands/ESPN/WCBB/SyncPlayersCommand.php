<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync WCBB players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching WCBB players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching WCBB players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('WCBB players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
