<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Jobs\ESPN\WCBB\FetchGameDetails;
use App\Models\WCBB\Game;
use Illuminate\Console\Command;

class SyncGameDetailsCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-game-details
                            {eventId? : The ESPN event ID (optional - syncs all completed games without stats if not provided)}';

    protected $description = 'Sync WCBB game details (plays and player stats) from ESPN API';

    public function handle(): int
    {
        $eventId = $this->argument('eventId');

        if ($eventId) {
            $this->info("Dispatching WCBB game details sync job for event {$eventId}...");
            FetchGameDetails::dispatch($eventId);
            $this->info('WCBB game details sync job dispatched successfully.');

            return Command::SUCCESS;
        }

        // Sync all completed games without stats
        $this->info('Finding all completed games without stats...');

        $games = Game::query()
            ->whereNotNull('espn_event_id')
            ->whereDoesntHave('playerStats')
            ->orderBy('game_date', 'asc')
            ->get();

        if ($games->isEmpty()) {
            $this->info('No completed games found without stats.');

            return Command::SUCCESS;
        }

        $this->info("Found {$games->count()} completed games without stats.");
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
