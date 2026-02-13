<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Jobs\ESPN\NBA\FetchPlayers;
use Illuminate\Console\Command;

class SyncPlayersCommand extends Command
{
    protected $signature = 'espn:sync-nba-players
                            {teamEspnId? : Optional ESPN team ID to sync a specific team}';

    protected $description = 'Sync NBA players from ESPN API';

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');

        if ($teamEspnId) {
            $this->info("Dispatching NBA players sync job for team {$teamEspnId}...");
        } else {
            $this->info('Dispatching NBA players sync job for all teams...');
        }

        FetchPlayers::dispatch($teamEspnId);

        $this->info('NBA players sync job dispatched successfully.');

        return Command::SUCCESS;
    }
}
