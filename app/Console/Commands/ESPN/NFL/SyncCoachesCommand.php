<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncCoaches;
use Illuminate\Console\Command;

class SyncCoachesCommand extends Command
{
    protected $signature = 'espn:sync-nfl-coaches
                            {--season= : NFL season to sync, defaults to nfl.season.default}';

    protected $description = 'Sync NFL head coach season assignments from ESPN';

    public function handle(SyncCoaches $syncCoaches): int
    {
        $season = (int) ($this->option('season') ?: config('nfl.season.default'));

        $this->info("Syncing NFL coaches for {$season}...");

        $count = $syncCoaches->execute($season);

        $this->info("Synced {$count} NFL coach season assignment(s).");

        return Command::SUCCESS;
    }
}
