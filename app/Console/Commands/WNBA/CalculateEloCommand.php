<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\CalculateElo;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Illuminate\Console\Command;

class CalculateEloCommand extends Command
{
    protected $signature = 'wnba:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate WNBA team Elo ratings based on completed games';

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
        $totalSkipped = 0;
        $skipIfExists = ! $this->option('reset');

        foreach ($games as $game) {
            $result = $calculateElo->execute($game, $skipIfExists);

            if ($result['skipped']) {
                $totalSkipped++;
            } elseif ($result['home_change'] != 0 || $result['away_change'] != 0) {
                $totalCalculated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($totalSkipped > 0) {
            $this->info("Elo calculation complete! {$totalCalculated} games calculated, {$totalSkipped} games skipped (already calculated).");
        } else {
            $this->info("Elo calculation complete! {$totalCalculated} games calculated.");
        }

        // Show top teams by Elo
        $this->newLine();
        $this->info('Top 10 Teams by Elo Rating:');

        $topTeams = Team::query()
            ->orderBy('elo_rating', 'desc')
            ->limit(10)
            ->get();

        $this->table(
            ['Rank', 'Team', 'Elo Rating'],
            $topTeams->map(fn ($team, $index) => [
                $index + 1,
                "{$team->city} {$team->name}",
                $team->elo_rating,
            ])
        );

        return Command::SUCCESS;
    }
}
