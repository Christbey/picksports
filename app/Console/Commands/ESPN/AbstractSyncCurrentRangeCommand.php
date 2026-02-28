<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\IteratesDateRange;
use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Carbon\Carbon;
use Illuminate\Console\Command;

abstract class AbstractSyncCurrentRangeCommand extends Command
{
    use IteratesDateRange;
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const TEAM_SYNC_JOB_CLASS = null;

    protected const CURRENT_DATE_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->buildDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->sportCode();

        if ($this->shouldSyncTeams()) {
            $this->info("Syncing {$sport} teams...");
            $this->dispatchTeamsSync();
        }

        $daysBack = (int) $this->option('days-back');
        $daysForward = (int) $this->option('days-forward');

        $startDate = Carbon::today()->subDays($daysBack);
        $endDate = Carbon::today()->addDays($daysForward);
        $totalDays = $this->inclusiveDayCount($startDate, $endDate);

        $this->info("Syncing {$sport} games from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$totalDays} days)...");
        $this->eachDateInRange($startDate, $endDate, function (Carbon $currentDate): void {
            $this->dispatchCurrentDateSync($currentDate->format('Ymd'));
        });

        $this->info("Dispatched {$totalDays} game sync jobs successfully.");

        return Command::SUCCESS;
    }

    protected function shouldSyncTeams(): bool
    {
        return $this->teamSyncJobClass() !== null;
    }

    protected function dispatchTeamsSync(): void
    {
        $job = $this->teamSyncJobClass();
        if ($job === null) {
            return;
        }

        $job::dispatch();
    }

    protected function dispatchCurrentDateSync(string $date): void
    {
        $job = $this->currentDateSyncJobClass();
        $job::dispatch($date);
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--days-back=7 : Number of days to sync backwards from today}\n {--days-forward=7 : Number of days to sync forward from today}",
            $this->commandName()
        );
    }

    protected function buildDescription(): string
    {
        $sport = $this->sportCode();

        if ($this->shouldSyncTeams()) {
            return "Sync {$sport} teams and games from the past and upcoming days";
        }

        return "Sync {$sport} games from the past and upcoming days";
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    /**
     * @return class-string|null
     */
    protected function teamSyncJobClass(): ?string
    {
        return static::TEAM_SYNC_JOB_CLASS;
    }

    /**
     * @return class-string
     */
    protected function currentDateSyncJobClass(): string
    {
        return $this->requiredJobClass(static::CURRENT_DATE_SYNC_JOB_CLASS, 'CURRENT_DATE_SYNC_JOB_CLASS');
    }
}
