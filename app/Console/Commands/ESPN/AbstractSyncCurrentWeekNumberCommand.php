<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;

abstract class AbstractSyncCurrentWeekNumberCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const SEASON_START_MONTH = 1;

    protected const SEASON_START_DAY = 1;

    protected const MAX_REGULAR_SEASON_WEEKS = 1;

    protected const REGULAR_SEASON_TYPE = 2;

    protected const TEAM_SYNC_JOB_CLASS = '';

    protected const WEEK_GAMES_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->commandName();
        $this->description = "Sync {$this->sportCode()} teams and current week games from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->sportCode();

        $this->info("Syncing {$sport} teams...");
        $this->dispatchTeamsSync();

        $currentYear = (int) date('Y');
        $currentWeek = $this->getCurrentWeek();

        $this->info("Syncing {$sport} games for Week {$currentWeek}...");
        $this->dispatchWeekGamesSync($currentYear, $currentWeek);

        $this->info('Sync jobs dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function getCurrentWeek(): int
    {
        $now = now();
        $seasonStart = now()->setMonth($this->seasonStartMonth())->setDay($this->seasonStartDay());

        if ($now->lessThan($seasonStart)) {
            return 1;
        }

        $weekNumber = $now->diffInWeeks($seasonStart) + 1;

        return min($weekNumber, $this->maxRegularSeasonWeeks());
    }

    protected function seasonStartMonth(): int
    {
        return static::SEASON_START_MONTH;
    }

    protected function seasonStartDay(): int
    {
        return static::SEASON_START_DAY;
    }

    protected function maxRegularSeasonWeeks(): int
    {
        return static::MAX_REGULAR_SEASON_WEEKS;
    }

    protected function dispatchTeamsSync(): void
    {
        $job = $this->teamSyncJobClass();
        $job::dispatch();
    }

    protected function dispatchWeekGamesSync(int $season, int $week): void
    {
        $job = $this->weekGamesSyncJobClass();
        $job::dispatch($season, $this->regularSeasonType(), $week);
    }

    protected function regularSeasonType(): int
    {
        return static::REGULAR_SEASON_TYPE;
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    /**
     * @return class-string
     */
    protected function teamSyncJobClass(): string
    {
        return $this->requiredJobClass(static::TEAM_SYNC_JOB_CLASS, 'TEAM_SYNC_JOB_CLASS');
    }

    /**
     * @return class-string
     */
    protected function weekGamesSyncJobClass(): string
    {
        return $this->requiredJobClass(static::WEEK_GAMES_SYNC_JOB_CLASS, 'WEEK_GAMES_SYNC_JOB_CLASS');
    }
}
