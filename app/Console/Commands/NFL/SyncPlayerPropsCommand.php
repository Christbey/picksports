<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;

class SyncPlayerPropsCommand extends Command
{
    protected $signature = 'nfl:sync-player-props
                            {--markets=* : Specific markets to fetch (defaults to common props)}';

    protected $description = 'Sync NFL player props from The Odds API';

    public function handle(\App\Actions\OddsApi\NFL\SyncPlayerPropsForGames $sync): int
    {
        $this->info('Syncing NFL player props from The Odds API...');

        $markets = $this->option('markets');
        $markets = empty($markets) ? null : $markets;

        $stored = $sync->execute($markets);

        $this->info("Successfully stored {$stored} player props.");

        return self::SUCCESS;
    }
}
