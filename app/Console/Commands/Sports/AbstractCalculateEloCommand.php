<?php

namespace App\Console\Commands\Sports;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractCalculateEloCommand extends Command
{
    /**
     * Get the sport name for display
     */
    abstract protected function getSportName(): string;

    /**
     * Get the Game model class
     */
    abstract protected function getGameModel(): string;

    /**
     * Get the Team model class
     */
    abstract protected function getTeamModel(): string;

    /**
     * Get the EloRating model class
     */
    abstract protected function getEloRatingModel(): string;

    /**
     * Get the CalculateElo action class
     */
    abstract protected function getCalculateEloAction(): string;

    /**
     * Get the default Elo rating
     */
    protected function getDefaultElo(): int
    {
        return 1500;
    }

    public function handle(): int
    {
        $calculateEloClass = $this->getCalculateEloAction();
        $calculateElo = new $calculateEloClass;

        // Reset Elo ratings if requested
        if ($this->option('reset')) {
            $this->info('Resetting all Elo ratings to '.$this->getDefaultElo().'...');
            $teamModel = $this->getTeamModel();
            $eloRatingModel = $this->getEloRatingModel();

            $teamModel::query()->update(['elo_rating' => $this->getDefaultElo()]);
            $eloRatingModel::query()->truncate();
            $this->info('Elo ratings reset successfully.');
        }

        // Build query for completed games
        $gameModel = $this->getGameModel();
        $query = $gameModel::query()
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

        $this->info("Calculating Elo for {$games->count()} completed games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $processed = 0;
        $skipped = 0;

        foreach ($games as $game) {
            $result = $calculateElo->execute($game, skipIfExists: ! $this->option('reset'));

            if ($result['skipped']) {
                $skipped++;
            } else {
                $processed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed {$processed} games.");
        $this->comment("Skipped {$skipped} games (already calculated).");

        return Command::SUCCESS;
    }
}
