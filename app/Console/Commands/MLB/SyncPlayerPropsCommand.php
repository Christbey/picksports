<?php

namespace App\Console\Commands\MLB;

use Illuminate\Console\Command;

class SyncPlayerPropsCommand extends Command
{
    protected $signature = 'mlb:sync-player-props
                            {--markets=* : Specific markets to fetch (defaults to common props)}';

    protected $description = 'Sync MLB player props from The Odds API';

    public function handle(\App\Actions\OddsApi\MLB\SyncPlayerPropsForGames $sync): int
    {
        $this->info('Syncing MLB player props from The Odds API...');

        $markets = $this->option('markets');
        $markets = empty($markets) ? null : $markets;

        $stored = $sync->execute($markets);

        $this->info("Successfully stored {$stored} player props.");

        return self::SUCCESS;
    }
}
