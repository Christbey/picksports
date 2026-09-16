<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPregamePipelineRunner;
use App\Services\NFL\Predictions\NflPregameHorizon;
use Illuminate\Console\Command;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'nfl:run-pregame-pipeline
        {--season= : NFL season, defaulting to the configured season}
        {--date= : Start the eight-day pregame horizon on this business date}
        {--days-forward=8 : Number of days covered by every pipeline step}';

    protected $description = 'Run NFL odds, legacy prediction, canonical prediction, and readiness steps sequentially';

    public function handle(NflPregamePipelineRunner $runner, NflPregameHorizon $horizons): int
    {
        if (! (bool) config('prediction_lifecycle.canonical_pipeline.nfl', false)) {
            $this->error('NFL pregame pipeline stopped: PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE is disabled.');

            return self::FAILURE;
        }

        $season = filled($this->option('season'))
            ? (int) $this->option('season')
            : (int) config('nfl.season.default');
        $daysForward = max(1, (int) $this->option('days-forward'));
        $horizon = $horizons->resolve(
            filled($this->option('date')) ? (string) $this->option('date') : null,
            $daysForward,
        );
        $shared = [
            '--season' => $season,
            '--date' => $horizon['date'],
            '--days-forward' => $daysForward,
        ];
        $steps = [
            [
                'name' => 'odds_sync',
                'command' => 'nfl:sync-odds',
                'arguments' => ['--days' => $daysForward],
            ],
            [
                'name' => 'legacy_generation',
                'command' => 'nfl:generate-predictions',
                'arguments' => $shared,
                // One held legacy game must not prevent immutable canonical
                // observations for the other games; the run still fails.
                'continue_on_failure' => true,
            ],
            [
                'name' => 'canonical_generation_and_readiness',
                'command' => 'nfl:generate-canonical-predictions',
                'arguments' => [...$shared, '--verify-readiness' => true],
            ],
        ];

        $result = $runner->run($steps, function (string $name, string $output, int $exitCode): void {
            if (trim($output) !== '') {
                $this->line(trim($output));
            }
            $this->line("NFL pregame step {$name} exited {$exitCode}.");
        });

        if (! $result['successful']) {
            $this->error("NFL pregame pipeline failed at {$result['failed_step']}; review the step output and readiness report.");

            return self::FAILURE;
        }

        $this->info("NFL pregame pipeline completed with full {$daysForward}-day readiness verification.");

        return self::SUCCESS;
    }
}
