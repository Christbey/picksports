<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Console\Command;

abstract class AbstractSyncTeamsCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const TEAMS_SYNC_JOB_CLASS = '';

    protected const TEAMS_SYNC_ACTION_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->commandName()."\n {--espn-id= : Sync a single ESPN team id immediately}";
        $this->description = "Sync {$this->sportCode()} teams from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->sportCode();
        $espnId = trim((string) $this->option('espn-id'));

        if ($espnId !== '') {
            $this->info("Syncing {$sport} team {$espnId}...");

            $synced = $this->teamsSyncAction()->executeForEspnId($espnId);

            if (! $synced) {
                $this->warn("Unable to sync {$sport} team {$espnId} from ESPN.");

                return Command::FAILURE;
            }

            $this->info("{$sport} team {$espnId} synced successfully.");

            return Command::SUCCESS;
        }

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

    protected function teamsSyncAction(): object
    {
        $actionClass = $this->requiredJobClass(static::TEAMS_SYNC_ACTION_CLASS, 'TEAMS_SYNC_ACTION_CLASS');

        return app($actionClass, ['espnService' => app($this->espnServiceClass())]);
    }

    /**
     * @return class-string<BaseEspnService>
     */
    protected function espnServiceClass(): string
    {
        $sport = $this->sportCode();

        return "App\\Services\\ESPN\\{$sport}\\EspnService";
    }
}
