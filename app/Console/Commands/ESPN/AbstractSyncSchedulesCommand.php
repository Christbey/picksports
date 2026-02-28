<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncSchedulesCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const ESPN_SERVICE_CLASS = '';

    protected const SCHEDULE_SYNC_ACTION_CLASS = '';

    protected const DEFAULT_SEASON = '2025';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} team schedules from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $season = (int) $this->option('season');
        $teamEspnId = $this->option('team');

        if ($teamEspnId) {
            return $this->syncTeamSchedule((string) $teamEspnId, $season);
        }

        $teamModelClass = $this->teamModelClass();
        $teams = $teamModelClass::all();
        $totalTeams = $teams->count();
        $totalGames = 0;
        $sport = $this->sportCode();

        $this->info("Syncing schedules for {$totalTeams} {$sport} teams (Season {$season})...");

        $progressBar = $this->output->createProgressBar($totalTeams);
        $progressBar->start();

        foreach ($teams as $team) {
            $count = $this->executeTeamScheduleSync((string) $team->espn_id, $season);
            $totalGames += $count;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully synced {$totalGames} games from {$totalTeams} team schedules.");

        return Command::SUCCESS;
    }

    protected function syncTeamSchedule(string $teamEspnId, int $season): int
    {
        $this->info("Syncing schedule for team {$teamEspnId} (Season {$season})...");

        $count = $this->executeTeamScheduleSync($teamEspnId, $season);

        $this->info("Successfully synced {$count} games for team {$teamEspnId}.");

        return Command::SUCCESS;
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season=%s : The season year}\n {--team= : Specific team ESPN ID (optional, syncs all teams if not provided)}",
            $this->commandName(),
            $this->defaultSeason()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    protected function defaultSeason(): string
    {
        return static::DEFAULT_SEASON;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        return $this->requiredJobClass(static::TEAM_MODEL_CLASS, 'TEAM_MODEL_CLASS');
    }

    /**
     * @return class-string
     */
    protected function espnServiceClass(): string
    {
        return $this->requiredJobClass(static::ESPN_SERVICE_CLASS, 'ESPN_SERVICE_CLASS');
    }

    /**
     * @return class-string
     */
    protected function scheduleSyncActionClass(): string
    {
        return $this->requiredJobClass(static::SCHEDULE_SYNC_ACTION_CLASS, 'SCHEDULE_SYNC_ACTION_CLASS');
    }

    protected function executeTeamScheduleSync(string $teamEspnId, int $season): int
    {
        $serviceClass = $this->espnServiceClass();
        $actionClass = $this->scheduleSyncActionClass();

        $service = new $serviceClass;
        $action = new $actionClass($service);

        return $action->execute($teamEspnId, $season);
    }
}
