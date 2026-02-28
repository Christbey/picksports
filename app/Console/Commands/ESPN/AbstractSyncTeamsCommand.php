<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;

abstract class AbstractSyncTeamsCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const TEAMS_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->commandName();
        $this->description = "Sync {$this->sportCode()} teams from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->sportCode();

        $this->info("Dispatching {$sport} teams sync job...");

        $this->dispatchTeamsSync();

        $this->info("{$sport} teams sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function dispatchTeamsSync(): void
    {
        $job = $this->teamsSyncJobClass();
        $job::dispatch();
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    /**
     * @return class-string
     */
    protected function teamsSyncJobClass(): string
    {
        return $this->requiredJobClass(static::TEAMS_SYNC_JOB_CLASS, 'TEAMS_SYNC_JOB_CLASS');
    }
}
