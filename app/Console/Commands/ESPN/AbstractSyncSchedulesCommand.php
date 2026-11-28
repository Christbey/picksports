<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} team schedules from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $season = $this->resolveSeason($this->option('season') ?? $this->defaultSeason());
        $teamEspnId = $this->option('team');

        if ($teamEspnId) {
            return $this->syncTeamSchedule((string) $teamEspnId, $season);
        }

        $teams = $this->teamsToSync($season);
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
            "%s\n {--season= : Season year (defaults to the configured sport season)}\n {--team= : Specific team ESPN ID (optional, syncs all teams if not provided)}",
            $this->commandName()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    protected function defaultSeason(): string
    {
        return (string) $this->resolveSeason(config(strtolower($this->sportCode()).'.season.default'));
    }

    protected function resolveSeason(mixed $season): int
    {
        if ((! is_int($season) && (! is_string($season) || preg_match('/\A[1-9][0-9]{0,3}\z/', $season) !== 1))
            || (int) $season < 1 || (int) $season > 9999) {
            throw new \LogicException('A valid explicit or configured season is required for schedule synchronization.');
        }

        return (int) $season;
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

    protected function teamsToSync(int $season): Collection
    {
        $teamModelClass = $this->teamModelClass();

        return $teamModelClass::all();
    }
}
