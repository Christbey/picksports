<?php

namespace App\Console\Commands\WCBB;

use App\Actions\OddsApi\WCBB\SyncOddsForGames;
use Illuminate\Console\Command;

class SyncOddsCommand extends Command
{
    protected $signature = 'wcbb:sync-odds
                            {--days= : Number of days ahead to sync odds for (default: 7)}';

    protected $description = 'Sync betting odds from The Odds API for WCBB games';

    public function handle(SyncOddsForGames $syncOddsForGames): int
    {
        $days = $this->option('days') ?? 7;

        $this->info("Syncing odds for upcoming games (next {$days} days)...");

        $updated = $syncOddsForGames->execute($days);

        if ($updated === 0) {
            $this->warn('No games were updated with odds data.');

            return Command::SUCCESS;
        }

        $this->info("Successfully updated odds for {$updated} games.");

        return Command::SUCCESS;
    }
}
