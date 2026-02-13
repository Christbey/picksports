<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\CalculateElo;
use App\Models\WCBB\EloRating;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use Illuminate\Console\Command;

class CalculateEloCommand extends Command
{
    protected $signature = 'wcbb:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--week= : Calculate Elo for a specific week}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate WCBB team Elo ratings based on completed games';

    public function handle(): int
    {
        $calculateElo = new CalculateElo;

        // Reset Elo ratings if requested
        if ($this->option('reset')) {
            $this->info('Resetting all Elo ratings to 1500...');
            Team::query()->update(['elo_rating' => 1500]);
            EloRating::query()->truncate();
            $this->info('Elo ratings reset successfully.');
        }

        // Build query for completed games
        $query = Game::query()
            ->where('status', 'STATUS_FINAL')
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

        if ($fromDate = $this->option('from-date')) {
            $query->where('game_date', '>=', $fromDate);
        }

        if ($toDate = $this->option('to-date')) {
            $query->where('game_date', '<=', $toDate);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No completed games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Calculating Elo ratings for {$games->count()} games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $totalCalculated = 0;

        foreach ($games as $game) {
            $result = $calculateElo->execute($game);

            if ($result['home_change'] != 0 || $result['away_change'] != 0) {
                $totalCalculated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Elo calculation complete! {$totalCalculated} games calculated.");

        // Show top teams by Elo
        $this->newLine();
        $this->info('Top 10 Teams by Elo Rating:');

        $topTeams = Team::query()
            ->orderBy('elo_rating', 'desc')
            ->limit(10)
            ->get();

        if ($topTeams->isNotEmpty()) {
            $this->table(
                ['Rank', 'Team', 'Elo Rating'],
                $topTeams->map(fn ($team, $index) => [
                    $index + 1,
                    $team->abbreviation,
                    $team->elo_rating,
                ])
            );
        }

        return Command::SUCCESS;
    }
}
