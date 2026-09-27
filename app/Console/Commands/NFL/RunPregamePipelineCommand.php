<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
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
        $startedAt = now()->startOfSecond();
        $expectedGameIds = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereHas('sportEvent', fn ($query) => $query->whereBetween('starts_at', [$horizon['start'], $horizon['end']]))
            ->pluck('id');
        $steps = [
            [
                'name' => 'odds_sync',
                'command' => 'nfl:sync-odds',
                'arguments' => ['--days' => $daysForward],
                // A provider outage must not prevent generation from valid stored
                // markets. Retain the failure in the final pipeline result.
                'continue_on_failure' => true,
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
                'continue_on_failure' => true,
            ],
            [
                'name' => 'research_assessments',
                'command' => 'nfl:research-pipeline',
                'arguments' => ['--date' => $horizon['date'], '--days-forward' => $daysForward, '--no-ingest' => true, '--no-web' => true, '--limit' => 100],
                'continue_on_failure' => true,
            ],
            [
                'name' => 'research_readiness',
                'command' => 'nfl:research-readiness',
                'arguments' => ['--days-forward' => min(2, $daysForward)],
            ],
        ];

        $result = $runner->run($steps, function (string $name, string $output, int $exitCode): void {
            if (trim($output) !== '') {
                $this->line(trim($output));
            }
            $this->line("NFL pregame step {$name} exited {$exitCode}.");
        });

        $generatedGameIds = PredictionFeatureSnapshot::query()
            ->where('sport', 'nfl')
            ->where('prediction_table', 'nfl_predictions')
            ->whereIn('game_id', $expectedGameIds)
            ->where('generated_at', '>=', $startedAt)
            ->pluck('game_id');
        $missingGameIds = $expectedGameIds->diff($generatedGameIds);
        if ($missingGameIds->isNotEmpty()) {
            $this->error('NFL pregame pipeline has no new prediction snapshots for game IDs: '.$missingGameIds->implode(', ').'.');

            return self::FAILURE;
        }

        if (! $result['successful']) {
            $this->error("NFL pregame pipeline failed at {$result['failed_step']}; review the step output and readiness report.");

            return self::FAILURE;
        }

        $this->info("NFL pregame pipeline completed with full {$daysForward}-day readiness verification.");

        return self::SUCCESS;
    }
}
