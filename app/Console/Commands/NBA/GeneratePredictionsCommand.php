<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'nba:generate-predictions
                            {--season= : Generate predictions for a specific season}
                            {--date= : Generate predictions for games on a specific date (YYYY-MM-DD)}
                            {--game= : Generate prediction for a specific game ID}';

    protected $description = 'Generate NBA game predictions based on Elo ratings and team metrics';

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

        if ($date = $this->option('date')) {
            $query->whereDate('game_date', $date);
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
                    $homeTeam = "{$game->homeTeam->school} {$game->homeTeam->mascot}";
                    $awayTeam = "{$game->awayTeam->school} {$game->awayTeam->mascot}";

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
        $homeTeam = "{$game->homeTeam->school} {$game->homeTeam->mascot}";
        $awayTeam = "{$game->awayTeam->school} {$game->awayTeam->mascot}";

        $this->newLine();
        $this->info("Game: {$awayTeam} @ {$homeTeam}");
        $this->info("Date: {$game->game_date->format('Y-m-d')}");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Home Elo', $prediction->home_elo],
                ['Away Elo', $prediction->away_elo],
                ['Home Off Eff', round($prediction->home_off_eff, 1)],
                ['Home Def Eff', round($prediction->home_def_eff, 1)],
                ['Away Off Eff', round($prediction->away_off_eff, 1)],
                ['Away Def Eff', round($prediction->away_def_eff, 1)],
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
