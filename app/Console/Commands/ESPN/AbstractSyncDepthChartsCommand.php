<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use App\Console\Commands\ESPN\Concerns\ResolvesSportCode;
use Illuminate\Console\Command;

abstract class AbstractSyncDepthChartsCommand extends Command
{
    use ResolvesJobClass;
    use ResolvesSportCode;

    protected const COMMAND_NAME = '';

    protected const SPORT_CODE = '';

    protected const DEPTH_CHARTS_SYNC_JOB_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = "Sync {$this->sportCode()} team depth charts from ESPN API";

        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->sportCode();
        $teamEspnId = $this->argument('teamEspnId');
        $season = (int) ($this->option('season') ?: now()->year);

        if ($teamEspnId) {
            $this->info("Dispatching {$sport} depth chart sync job for team {$teamEspnId} and season {$season}...");
        } else {
            $this->info("Dispatching {$sport} depth chart sync job for all teams in season {$season}...");
        }

        $job = $this->depthChartsSyncJobClass();
        $job::dispatch($teamEspnId !== null ? (string) $teamEspnId : null, $season);

        $this->info("{$sport} depth chart sync job dispatched successfully.");

        return Command::SUCCESS;
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {teamEspnId? : Optional ESPN team ID to sync a specific team}\n {--season= : Season year to sync}",
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
    protected function depthChartsSyncJobClass(): string
    {
        return $this->requiredJobClass(static::DEPTH_CHARTS_SYNC_JOB_CLASS, 'DEPTH_CHARTS_SYNC_JOB_CLASS');
    }
}
