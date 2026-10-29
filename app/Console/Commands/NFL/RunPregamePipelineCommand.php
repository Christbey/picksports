<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\NFL\NflPregamePipelineRunner;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use Illuminate\Console\Command;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'nfl:run-pregame-pipeline
        {--season= : NFL season, defaulting to the configured season}
        {--date= : Start the eight-day pregame horizon on this business date}
        {--days-forward=8 : Number of days covered by every pipeline step}';

    protected $description = 'Run NFL canonical prediction, frozen recommendation evidence, and readiness steps using stored odds';

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
        $freshAfter = now()->subMinutes(max(1, (int) config('nfl.predictions.pregame_market.maximum_prediction_age_minutes', 360)));
        $expectedGameIds = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereHas('sportEvent', fn ($query) => $query->whereBetween('starts_at', [$horizon['start'], $horizon['end']]))
            ->pluck('id');
        $steps = [
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

        $generatedEventIds = app(CanonicalSportPredictionQuery::class)->queryForSport('nfl')
            ->where('predictions.generated_at', '>=', $freshAfter)
            ->whereHas('calculationRun.inputSnapshot', fn ($query) => $query
                ->where('captured_at', '>=', $freshAfter)
                ->where('inputs->recommendation_context->version', NflRecommendationInputBuilder::VERSION))
            ->pluck('predictions.sport_event_id');
        $generatedGameIds = Game::query()->whereIn('sport_event_id', $generatedEventIds)->pluck('id');
        $missingGameIds = $expectedGameIds->diff($generatedGameIds);
        if ($missingGameIds->isNotEmpty()) {
            $this->error('NFL pregame pipeline has no current canonical input evidence for game IDs: '.$missingGameIds->implode(', ').'.');

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
