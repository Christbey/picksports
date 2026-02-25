<?php

namespace App\Console\Commands\NBA;

use Illuminate\Console\Command;

class SyncPlayerPropsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nba:sync-player-props
                            {--markets=* : Specific markets to fetch (defaults to common props)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync NBA player props from The Odds API';

    /**
     * Execute the console command.
     */
    public function handle(\App\Actions\OddsApi\NBA\SyncPlayerPropsForGames $sync): int
    {
        $this->info('Syncing NBA player props from The Odds API...');

        $markets = $this->option('markets');
        $markets = empty($markets) ? null : $markets;

        $stored = $sync->execute($markets);

        $this->info("Successfully stored {$stored} player props.");

        return self::SUCCESS;
    }
}
