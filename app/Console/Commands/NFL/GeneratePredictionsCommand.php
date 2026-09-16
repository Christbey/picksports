<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Services\NFL\NflPredictionDispositionRecorder;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\NFL\NflTrueEpaReadinessService;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\Sports\SportsDateWindowService;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'nfl:generate-predictions
                            {--season= : Season to generate predictions for (defaults to config nfl.season.default)}
                            {--date= : Start the bounded pregame horizon on this business date (YYYY-MM-DD)}
                            {--days-forward= : Limit generation to this many days from the horizon start}
                            {--from-date= : Generate predictions starting from this date (YYYY-MM-DD)}
                            {--to-date= : Generate predictions up to this date (YYYY-MM-DD)}';

    protected $description = 'Generate predictions for NFL games using current ELO ratings';

    public function handle(
        SportsDateWindowService $dateWindows,
        NflTrueEpaReadinessService $trueEpaReadiness,
        NflReleasedBetDecisionRecorder $betDecisionRecorder,
        NflPredictionDispositionRecorder $dispositionRecorder,
    ): int {
        $generatePrediction = app(GeneratePredictionFromHistoricalElo::class);

        // Build query for upcoming games
        $query = Game::query()
            ->where('status', '!=', 'STATUS_FINAL')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('id');

        $season = $this->option('season') ?? config('nfl.season.default');
        $query->where('season', $season);

        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        $usePregameHorizon = filled($this->option('date')) || filled($this->option('days-forward'));

        if ($usePregameHorizon) {
            $query->whereIn('season_type', NflPregameHorizon::seasonTypes())
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED']);
            $horizon = app(NflPregameHorizon::class)->resolve(
                filled($this->option('date')) ? (string) $this->option('date') : null,
                filled($this->option('days-forward')) ? (int) $this->option('days-forward') : 8,
            );
            $query->whereHas('sportEvent', fn ($query) => $query
                ->where('starts_at', '>=', $horizon['start'])
                ->where('starts_at', '<=', $horizon['end']));
        } elseif ($fromDate && $toDate) {
            $dateWindows->applyGameDateWindow($query, $dateWindows->forRange($fromDate, $toDate));
        } else {
            $query
                ->when($fromDate, fn ($query, $date) => $query->whereDate('game_date', '>=', $date))
                ->when($toDate, fn ($query, $date) => $query->whereDate('game_date', '<=', $date));
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No upcoming games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Generating predictions for {$games->count()} games...");

        $epa = $trueEpaReadiness->prepare($games);
        if ($epa['enabled'] && $epa['attempted'] > 0) {
            $this->line(sprintf(
                'True EPA preflight checked %d team(s); refreshed %d of %d incomplete metric row(s).',
                $epa['checked'],
                $epa['backfilled'],
                $epa['attempted'],
            ));
        }

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $totalCreated = 0;
        $totalUpdated = 0;
        $trueEpaHolds = 0;
        $trueEpaHeldGameIds = [];
        $missingExplicitHoldGameIds = [];
        $candidateDecisionHolds = [];
        $failedGameIds = [];

        foreach ($games as $game) {
            try {
                $result = $generatePrediction->execute($game);

                if ($result === 'created') {
                    $totalCreated++;
                } elseif ($result === 'updated') {
                    $totalUpdated++;
                }

                if (in_array($result, ['created', 'updated'], true)) {
                    $prediction = $game->prediction()->first();
                    if ($prediction !== null) {
                        $coverage = $betDecisionRecorder->recordWithCoverage($prediction);
                        $dispositionRecorder->record($prediction, $coverage['candidate_market_keys']);
                        if ($coverage['missing_market_keys'] !== []) {
                            $candidateDecisionHolds[(int) $game->getKey()] = [
                                'missing_market_keys' => $coverage['missing_market_keys'],
                                'hold_reasons' => $coverage['hold_reasons'],
                            ];
                        }
                    }
                }

                if (config('nfl.predictions.true_epa.enabled', false)) {
                    $metadata = Prediction::query()->where('game_id', $game->id)->value('model_metadata');
                    if (is_string($metadata)) {
                        $metadata = json_decode($metadata, true);
                    }
                    if (is_array($metadata) && data_get($metadata, 'true_epa.applied') !== true) {
                        $trueEpaHolds++;
                        $trueEpaHeldGameIds[] = (int) $game->getKey();

                        $dataReasons = (array) data_get($metadata, 'analysis_layer.eligibility.data_reasons', []);
                        if (data_get($metadata, 'analysis_layer.eligibility.status') !== 'hold'
                            || ! in_array('missing_true_epa', $dataReasons, true)) {
                            $missingExplicitHoldGameIds[] = (int) $game->getKey();
                        }
                    }
                }

            } catch (\Throwable $exception) {
                report($exception);
                $failedGameIds[] = (int) $game->getKey();
                $this->newLine();
                $this->error("Game {$game->getKey()} failed: {$exception->getMessage()}");
            } finally {
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Prediction generation complete! {$totalCreated} created, {$totalUpdated} updated.");
        if ($failedGameIds !== []) {
            $this->error('Prediction generation failed for game IDs: '.implode(', ', $failedGameIds).'.');
        }
        if ($trueEpaHolds > 0) {
            $this->warn(sprintf(
                '%d prediction(s) have unavailable true EPA and cannot be recommendation-ready. Held game IDs: %s.',
                $trueEpaHolds,
                implode(', ', $trueEpaHeldGameIds),
            ));
            if ($missingExplicitHoldGameIds !== []) {
                $this->error(sprintf(
                    'True EPA was unavailable without an explicit missing_true_epa eligibility hold for game IDs: %s.',
                    implode(', ', $missingExplicitHoldGameIds),
                ));
            }
        }
        if ($candidateDecisionHolds !== []) {
            $this->error(sprintf(
                '%d game(s) contain an eligible official candidate that was explicitly held because no releasable pregame decision could be persisted: %s.',
                count($candidateDecisionHolds),
                json_encode($candidateDecisionHolds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ));
        }
        if (($totalCreated + $totalUpdated) > 0) {
            app(SportsViewCache::class)->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_LIVE_SCOREBOARD,
                SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
                SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
            ]);
        }

        return $trueEpaHolds === 0 && $candidateDecisionHolds === [] && $failedGameIds === []
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
