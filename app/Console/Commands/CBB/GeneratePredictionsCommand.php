<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'cbb:generate-predictions
                            {--season= : Generate predictions for a specific season}
                            {--week= : Generate predictions for games in a specific week}
                            {--date= : Generate predictions for games on a specific date (YYYY-MM-DD)}
                            {--game= : Generate prediction for a specific game ID}';

    protected $description = 'Generate CBB game predictions based on Elo ratings and team metrics';

    public function handle(): int
    {
        $generatePrediction = new GeneratePrediction;

        // Handle specific game
        if ($gameId = $this->option('game')) {
            $game = Game::find($gameId);

            if (! $game) {
                $this->error("Game with ID {$gameId} not found.");

                return Command::FAILURE;
            }

            $this->info("Generating prediction for game {$gameId}...");

            $prediction = $generatePrediction->execute($game);

            if (! $prediction) {
                $this->warn('Could not generate prediction (game may be completed or missing teams).');

                return Command::SUCCESS;
            }

            $this->displayPrediction($prediction);

            return Command::SUCCESS;
        }

        // Build query for upcoming games
        $query = Game::query()
            ->where('status', '!=', 'STATUS_FINAL')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('id');

        // Apply filters
        if ($season = $this->option('season')) {
            $query->where('season', $season);
        }

        if ($week = $this->option('week')) {
            $query->where('week', $week);
        }

        if ($date = $this->option('date')) {
            // Convert ET date to UTC datetime range
            // Games are stored in UTC but we want to filter by ET date
            // Example: 2026-03-07 ET spans from 2026-03-07 05:00:00 UTC to 2026-03-08 04:59:59 UTC
            $etDate = Carbon::parse($date, 'America/New_York');
            $utcStart = $etDate->copy()->setTimezone('UTC');
            $utcEnd = $etDate->copy()->endOfDay()->setTimezone('UTC');

            $query->whereRaw(
                "datetime(date(game_date) || ' ' || game_time) >= ? AND datetime(date(game_date) || ' ' || game_time) <= ?",
                [$utcStart->toDateTimeString(), $utcEnd->toDateTimeString()]
            );
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No upcoming games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Generating predictions for {$games->count()} games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $generated = 0;

        foreach ($games as $game) {
            $prediction = $generatePrediction->execute($game);

            if ($prediction) {
                $generated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Predictions generated for {$generated} games.");

        // Show top predictions by confidence
        $this->newLine();
        $this->info('Top 10 Predictions by Confidence:');

        $topPredictions = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->orderBy('confidence_score', 'desc')
            ->limit(10)
            ->get();

        if ($topPredictions->isNotEmpty()) {
            $this->table(
                ['Game', 'Spread', 'Total', 'Win %', 'Confidence'],
                $topPredictions->map(function ($pred) {
                    $game = $pred->game;
                    $homeTeam = $game->homeTeam->abbreviation;
                    $awayTeam = $game->awayTeam->abbreviation;

                    return [
                        "{$awayTeam} @ {$homeTeam}",
                        $pred->predicted_spread > 0
                            ? "{$homeTeam} -{$pred->predicted_spread}"
                            : "{$awayTeam} -".abs($pred->predicted_spread),
                        round($pred->predicted_total, 1),
                        round($pred->win_probability * 100, 1).'%',
                        round($pred->confidence_score, 1),
                    ];
                })
            );
        }

        return Command::SUCCESS;
    }

    protected function displayPrediction(Prediction $prediction): void
    {
        $game = $prediction->game;
        $homeTeam = $game->homeTeam->abbreviation;
        $awayTeam = $game->awayTeam->abbreviation;

        $this->newLine();
        $this->info("Game: {$awayTeam} @ {$homeTeam}");
        $this->info("Date: {$game->game_date}");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Home Elo', $prediction->home_elo],
                ['Away Elo', $prediction->away_elo],
                ['Home Off Rating', round($prediction->home_off_eff, 1)],
                ['Home Def Rating', round($prediction->home_def_eff, 1)],
                ['Away Off Rating', round($prediction->away_off_eff, 1)],
                ['Away Def Rating', round($prediction->away_def_eff, 1)],
                ['Predicted Spread', $prediction->predicted_spread > 0
                    ? "{$homeTeam} -{$prediction->predicted_spread}"
                    : "{$awayTeam} -".abs($prediction->predicted_spread), ],
                ['Predicted Total', round($prediction->predicted_total, 1)],
                ['Win Probability', round($prediction->win_probability * 100, 1).'%'],
                ['Confidence Score', round($prediction->confidence_score, 1)],
            ]
        );
    }
}
