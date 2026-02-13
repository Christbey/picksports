<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Jobs\ESPN\WNBA\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-wnba-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync WNBA players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching WNBA players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching WNBA players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('WNBA players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
