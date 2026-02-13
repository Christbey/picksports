<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Jobs\ESPN\MLB\FetchGameDetails;
use App\Models\MLB\Game;
use Illuminate\Console\Command;

class SyncGameDetailsCommand extends Command
{
    protected $signature = 'espn:sync-mlb-game-details
                            {eventId? : The ESPN event ID (optional - syncs all completed games without stats if not provided)}';

    protected $description = 'Sync MLB game details (plays and player stats) from ESPN API';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        if ($eventId) {
            $this->info("Dispatching MLB game details sync job for event {$eventId}...");
            FetchGameDetails::dispatch($eventId);
            $this->info('MLB game details sync job dispatched successfully.');

            return Command::SUCCESS;
        }

        // Sync all past games without linescores
        $this->info('Finding all past games without linescores...');

        $games = Game::query()
            ->whereDate('game_date', '<', now())
            ->whereNotNull('espn_event_id')
            ->whereNull('home_linescores')
            ->orderBy('game_date', 'asc')
            ->get();

        if ($games->isEmpty()) {
            $this->info('No past games found without linescores.');

            return Command::SUCCESS;
        }

        $this->info("Found {$games->count()} past games without linescores.");
        $this->info('Dispatching game details sync jobs...');

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        foreach ($games as $game) {
            FetchGameDetails::dispatch($game->espn_event_id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Dispatched {$games->count()} game details sync jobs successfully.");

        return Command::SUCCESS;
    }
}
