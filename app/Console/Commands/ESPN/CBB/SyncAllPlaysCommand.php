<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Jobs\ESPN\CBB\FetchPlays;
use App\Models\CBB\Game;
use Illuminate\Console\Command;

class SyncAllPlaysCommand extends Command
{
    protected $signature = 'espn:sync-all-cbb-plays';

    protected $description = 'Dispatch jobs to sync play-by-play data for all completed CBB games';

    public function handle(): int
    {
        $completedGames = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('espn_event_id')
            ->get();

        $this->info("Found {$completedGames->count()} completed games. Dispatching play sync jobs...");

        foreach ($completedGames as $game) {
            FetchPlays::dispatch($game->espn_event_id);
        }

        $this->info("Dispatched {$completedGames->count()} play sync jobs successfully.");

        return Command::SUCCESS;
    }
}
