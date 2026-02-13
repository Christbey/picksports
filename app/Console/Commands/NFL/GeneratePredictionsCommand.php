<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
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
            $result = $this->generatePrediction($game);

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

    protected function generatePrediction(Game $game): string
    {
        if (! $game->homeTeam || ! $game->awayTeam) {
            return 'skipped';
        }

        // Get ELO ratings at the time of the game
        $homeElo = $this->getEloAtDate($game->home_team_id, $game->game_date);
        $awayElo = $this->getEloAtDate($game->away_team_id, $game->game_date);

        // Apply home field advantage (unless neutral site)
        $homeFieldAdvantage = config('nfl.elo.home_field_advantage');
        $adjustedHomeElo = $game->neutral_site ? $homeElo : $homeElo + $homeFieldAdvantage;

        // Calculate win probability
        $winProbability = $this->calculateWinProbability($adjustedHomeElo, $awayElo);

        // Calculate predicted spread (capped at configured limits)
        $eloDiff = $adjustedHomeElo - $awayElo;
        $pointsPerElo = config('nfl.predictions.points_per_elo');
        $predictedSpread = $eloDiff * $pointsPerElo;
        $minSpread = config('nfl.predictions.min_spread');
        $maxSpread = config('nfl.predictions.max_spread');
        $predictedSpread = max($minSpread, min($maxSpread, $predictedSpread));

        // Calculate confidence score (distance from 50%)
        $confidenceScore = abs($winProbability - 0.5) * 2; // 0-1 scale

        // Calculate predicted total
        // Use average total and adjust based on combined team strength
        $averageTotal = config('nfl.predictions.average_total');
        $defaultElo = config('nfl.elo.default_rating');
        $combinedEloBonus = (($homeElo + $awayElo) - (2 * $defaultElo)) / 100;
        $predictedTotal = $averageTotal + $combinedEloBonus;

        // Create or update prediction
        $existing = Prediction::query()->where('game_id', $game->id)->first();

        Prediction::updateOrCreate(
            ['game_id' => $game->id],
            [
                'home_elo' => round($homeElo, 1),
                'away_elo' => round($awayElo, 1),
                'predicted_spread' => round($predictedSpread, 1),
                'predicted_total' => round($predictedTotal, 1),
                'win_probability' => round($winProbability, 3),
                'confidence_score' => round($confidenceScore, 2),
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    protected function getEloAtDate(int $teamId, $gameDate): float
    {
        // Get the most recent ELO rating before or on the game date
        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->where('date', '<=', $gameDate)
            ->orderBy('date', 'desc')
            ->first();

        // If no rating found, return default
        return $eloRecord ? (float) $eloRecord->elo_rating : config('nfl.elo.default_rating');
    }

    protected function calculateWinProbability(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }
}
