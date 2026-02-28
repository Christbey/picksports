<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

abstract class AbstractSyncGameDetailsCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const GAME_DETAILS_JOB_CLASS = '';

    protected const PENDING_GAMES_DESCRIPTOR = 'completed games without stats';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} game details (plays and player stats) from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = $this->argument('eventId');
        $sport = $this->sportCode();

        if ($eventId) {
            $this->info("Dispatching {$sport} game details sync job for event {$eventId}...");
            $this->dispatchGameDetailsSync((string) $eventId);
            $this->info("{$sport} game details sync job dispatched successfully.");

            return Command::SUCCESS;
        }

        $descriptor = $this->pendingGamesDescriptor();

        $this->info("Finding all {$descriptor}...");

        $games = $this->pendingGames();

        if ($games->isEmpty()) {
            $this->info("No {$descriptor} found.");

            return Command::SUCCESS;
        }

        $this->info("Found {$games->count()} {$descriptor}.");
        $this->info('Dispatching game details sync jobs...');

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        foreach ($games as $game) {
            $this->dispatchGameDetailsSync((string) $game->espn_event_id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Dispatched {$games->count()} game details sync jobs successfully.");

        return Command::SUCCESS;
    }

    protected function pendingGamesDescriptor(): string
    {
        return static::PENDING_GAMES_DESCRIPTOR;
    }

    protected function dispatchGameDetailsSync(string $eventId): void
    {
        $job = $this->gameDetailsJobClass();
        $job::dispatch($eventId);
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {eventId? : The ESPN event ID (optional - syncs all completed games without stats if not provided)}",
            $this->commandName()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    /**
     * @return class-string
     */
    protected function gameDetailsJobClass(): string
    {
        return $this->requiredJobClass(static::GAME_DETAILS_JOB_CLASS, 'GAME_DETAILS_JOB_CLASS');
    }

    abstract protected function pendingGames(): Collection;
}
