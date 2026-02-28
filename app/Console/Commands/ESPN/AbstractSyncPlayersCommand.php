<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;

abstract class AbstractSyncPlayersCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const PLAYERS_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} players from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $teamEspnId = $this->argument('teamEspnId');
        $sport = $this->sportCode();

        if ($teamEspnId) {
            $this->info("Dispatching {$sport} players sync job for team {$teamEspnId}...");
        } else {
            $this->info("Dispatching {$sport} players sync job for all teams...");
        }

        $this->dispatchPlayersSync($teamEspnId !== null ? (string) $teamEspnId : null);

        $this->info("{$sport} players sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function dispatchPlayersSync(?string $teamEspnId): void
    {
        $job = $this->playersSyncJobClass();
        $job::dispatch($teamEspnId);
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {teamEspnId? : Optional ESPN team ID to sync a specific team}",
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
    protected function playersSyncJobClass(): string
    {
        return $this->requiredJobClass(static::PLAYERS_SYNC_JOB_CLASS, 'PLAYERS_SYNC_JOB_CLASS');
    }
}
