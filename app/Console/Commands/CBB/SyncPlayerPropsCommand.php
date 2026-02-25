<?php

namespace App\Console\Commands\CBB;

use Illuminate\Console\Command;

class SyncPlayerPropsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbb:sync-player-props
                            {--markets=* : Specific markets to fetch (defaults to common props)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync CBB player props from The Odds API';

    /**
     * Execute the console command.
     */
    public function handle(\App\Actions\OddsApi\CBB\SyncPlayerPropsForGames $sync): int
    {
        $this->info('Syncing CBB player props from The Odds API...');

        $markets = $this->option('markets');
        $markets = empty($markets) ? null : $markets;

        $stored = $sync->execute($markets);

        $this->info("Successfully stored {$stored} player props.");

        return self::SUCCESS;
    }
}
