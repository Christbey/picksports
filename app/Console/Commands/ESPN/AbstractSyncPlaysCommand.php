<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;

abstract class AbstractSyncPlaysCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const PLAYS_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} play-by-play data from ESPN API for a specific game";

        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (string) $this->argument('eventId');
        $sport = $this->sportCode();

        $this->info("Dispatching {$sport} plays sync job for event {$eventId}...");

        $this->dispatchPlaysSync($eventId);

        $this->info("{$sport} plays sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function dispatchPlaysSync(string $eventId): void
    {
        $job = $this->playsSyncJobClass();
        $job::dispatch($eventId);
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {eventId : The ESPN event/game ID}",
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
    protected function playsSyncJobClass(): string
    {
        return $this->requiredJobClass(static::PLAYS_SYNC_JOB_CLASS, 'PLAYS_SYNC_JOB_CLASS');
    }
}
