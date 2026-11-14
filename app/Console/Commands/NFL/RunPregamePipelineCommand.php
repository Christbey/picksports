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

    protected $description = 'Generate NFL canonical predictions using stored odds and verify fresh published forecasts';

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
        $expectedGames = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereHas('sportEvent', fn ($query) => $query->whereBetween('starts_at', [$horizon['start'], $horizon['end']]))
            ->pluck('sport_event_id', 'id');
        $steps = [
            [
                'name' => 'canonical_generation',
                'command' => 'nfl:generate-canonical-predictions',
                'arguments' => $shared,
            ],
        ];

        $result = $runner->run($steps, function (string $name, string $output, int $exitCode): void {
            if (trim($output) !== '') {
                $this->line(trim($output));
            }
            $this->line("NFL pregame step {$name} exited {$exitCode}.");
        });

        if (! $result['successful']) {
            $this->error("NFL pregame pipeline failed at {$result['failed_step']}; review the step output.");

            return self::FAILURE;
        }

        $generatedEventIds = app(CanonicalSportPredictionQuery::class)->queryForSport('nfl')
            ->reorder()
            ->whereIn('predictions.sport_event_id', $expectedGames->values())
            ->where('predictions.published_at', '<=', now())
            ->where('predictions.generated_at', '>=', $freshAfter)
            ->whereHas('calculationRun.inputSnapshot', fn ($query) => $query
                ->where('captured_at', '>=', $freshAfter)
                ->where('inputs->recommendation_context->version', NflRecommendationInputBuilder::VERSION))
            ->distinct()
            ->pluck('predictions.sport_event_id');
        $missingGameIds = $expectedGames->diff($generatedEventIds)->keys();
        if ($missingGameIds->isNotEmpty()) {
            $this->error('NFL pregame pipeline has no current canonical input evidence for game IDs: '.$missingGameIds->implode(', ').'.');

            return self::FAILURE;
        }

        $this->info("NFL pregame pipeline verified fresh published forecasts for {$expectedGames->count()} game(s) across {$daysForward} days.");

        return self::SUCCESS;
    }
}
