<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'nfl:generate-predictions
                            {--season=2025 : Season to generate predictions for}
                            {--from-date= : Generate predictions starting from this date (YYYY-MM-DD)}
                            {--to-date= : Generate predictions up to this date (YYYY-MM-DD)}';

    protected $description = 'Generate predictions for NFL games using current ELO ratings';

    public function handle(): int
    {
        $generatePrediction = app(GeneratePredictionFromHistoricalElo::class);

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

        if ($fromDate = $this->option('from-date')) {
            $query->where('game_date', '>=', $fromDate);
        }

        if ($toDate = $this->option('to-date')) {
            $query->where('game_date', '<=', $toDate);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No upcoming games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Generating predictions for {$games->count()} games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($games as $game) {
            $result = $generatePrediction->execute($game);

            if ($result === 'created') {
                $totalCreated++;
            } elseif ($result === 'updated') {
                $totalUpdated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Prediction generation complete! {$totalCreated} created, {$totalUpdated} updated.");

        return Command::SUCCESS;
    }
}
