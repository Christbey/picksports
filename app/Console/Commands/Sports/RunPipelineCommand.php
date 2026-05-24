<?php

namespace App\Console\Commands\Sports;

use App\Services\Sports\PipelineCommandRunner;
use App\Services\Sports\SportsPipelineRegistry;
use Illuminate\Console\Command;

class RunPipelineCommand extends Command
{
    protected $signature = 'sports:run-pipeline
        {sport : Sport domain (nba, nfl, mlb, cbb, wcbb, wnba, cfb)}
        {--mode=full : Pipeline mode (sync, predict, full, live, ai)}
        {--season= : Override season/year used for season-aware commands}
        {--week= : Override week used for sport-specific pipeline steps}
        {--date= : Reference date for date-aware pipeline steps (YYYY-MM-DD)}
        {--dry-run : Print the pipeline plan without executing commands}';

    protected $description = 'Run a manual command pipeline for a supported sports domain';

    public function handle(PipelineCommandRunner $runner, SportsPipelineRegistry $registry): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        $mode = strtolower((string) $this->option('mode'));

        if (! $registry->supportsSport($sport)) {
            $this->error('Unsupported sport ['.$sport.']. Supported sports: '.implode(', ', $registry->supportedSports()).'.');

            return self::FAILURE;
        }

        if (! $registry->supportsMode($mode)) {
            $this->error('Unsupported mode ['.$mode.']. Supported modes: '.implode(', ', $registry->supportedModes()).'.');

            return self::FAILURE;
        }

        $context = $registry->context(
            date: $this->option('date'),
            season: $this->option('season'),
            week: $this->option('week'),
        );
        $steps = $registry->pipelineSteps($sport, $mode, $context);

        if ($steps === []) {
            $this->warn("No steps configured for sport [{$sport}] in mode [{$mode}].");

            return self::SUCCESS;
        }

        $this->info('Pipeline: '.strtoupper($sport).' ['.$mode.']');

        if ($this->option('dry-run')) {
            $this->warn('Dry run only. No commands will be executed.');
            $this->renderPipelinePlan($steps);

            return self::SUCCESS;
        }

        foreach ($steps as $index => $step) {
            $position = $index + 1;
            $this->line("[{$position}/".count($steps).'] '.$step['label'].' -> '.$this->renderCommand($step['command'], $step['arguments']));
            $exitCode = $runner->call($step['command'], $step['arguments']);

            if ($exitCode !== 0) {
                $this->error('Step failed: '.$this->renderCommand($step['command'], $step['arguments']));

                return self::FAILURE;
            }
        }

        $this->info('Pipeline completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{label: string, command: string, arguments: array<string, mixed>}>  $steps
     */
    protected function renderPipelinePlan(array $steps): void
    {
        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step['label'].' -> '.$this->renderCommand($step['command'], $step['arguments']));
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function renderCommand(string $command, array $arguments = []): string
    {
        $parts = [$command];

        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = $key.'='.$item;
                }

                continue;
            }

            if (str_starts_with($key, '--')) {
                $parts[] = $key.'='.$value;

                continue;
            }

            $parts[] = (string) $value;
        }

        return implode(' ', $parts);
    }
}
