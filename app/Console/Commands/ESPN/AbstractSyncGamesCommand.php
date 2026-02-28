<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use Illuminate\Console\Command;

abstract class AbstractSyncGamesCommand extends Command
{
    use ResolvesSportCode;
    use ResolvesJobClass;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const GAMES_SYNC_JOB_CLASS = '';

    protected const DEFAULT_SEASON_TYPE = '2';

    protected const SEASON_TYPE_DESCRIPTION = 'The season type (1=preseason, 2=regular, 3=postseason)';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} games from ESPN API for a specific week";

        parent::__construct();
    }

    public function handle(): int
    {
        $season = (int) $this->argument('season');
        $seasonType = (int) $this->argument('seasonType');
        $week = (int) $this->argument('week');
        $sport = $this->sportCode();

        $this->info("Dispatching {$sport} games sync job for Season {$season}, Week {$week}...");

        $this->dispatchGamesSync($season, $seasonType, $week);

        $this->info("{$sport} games sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function dispatchGamesSync(int $season, int $seasonType, int $week): void
    {
        $job = $this->gamesSyncJobClass();
        $job::dispatch($season, $seasonType, $week);
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {season : The season year}\n {week : The week number}\n {seasonType=%s : %s}",
            $this->commandName(),
            $this->defaultSeasonType(),
            $this->seasonTypeDescription()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    protected function defaultSeasonType(): string
    {
        return static::DEFAULT_SEASON_TYPE;
    }

    protected function seasonTypeDescription(): string
    {
        return static::SEASON_TYPE_DESCRIPTION;
    }

    /**
     * @return class-string
     */
    protected function gamesSyncJobClass(): string
    {
        return $this->requiredJobClass(static::GAMES_SYNC_JOB_CLASS, 'GAMES_SYNC_JOB_CLASS');
    }
}
