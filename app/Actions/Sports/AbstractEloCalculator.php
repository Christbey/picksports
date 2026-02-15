<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractEloCalculator
{
    /**
     * Get the sport identifier for config lookups (e.g., 'nba', 'nfl')
     */
    abstract protected function getSport(): string;

    /**
     * Get the EloRating model class for this sport
     */
    abstract protected function getEloRatingModel(): string;

    /**
     * Calculate sport-specific K-factor
     */
    abstract protected function calculateKFactor(Model $game): float;

    /**
     * Determine if game is a playoff game (sport-specific logic)
     */
    abstract protected function isPlayoffGame(Model $game): bool;

    /**
     * Calculate margin of victory multiplier (sport-specific logic)
     */
    abstract protected function calculateMarginMultiplier(Model $game): float;

    /**
     * Execute Elo calculation for a game
     */
    public function execute(Model $game, bool $skipIfExists = true): array
    {
        if ($game->status !== 'STATUS_FINAL') {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => false];
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => false];
        }

        // Check if Elo has already been calculated for this game
        if ($skipIfExists && $this->eloAlreadyCalculated($game, $homeTeam, $awayTeam)) {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
        }

        // Get current Elo ratings using dynamic config
        $sport = $this->getSport();
        $defaultElo = config("{$sport}.elo.default") ?? config("{$sport}.elo.default_rating");
        $homeAdvantage = config("{$sport}.elo.home_court_advantage")
                      ?? config("{$sport}.elo.home_field_advantage");

        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Adjust for home advantage
        $adjustedHomeElo = $homeElo + $homeAdvantage;

        // Calculate expected win probabilities
        $homeExpected = $this->calculateExpectedScore($adjustedHomeElo, $awayElo);
        $awayExpected = 1 - $homeExpected;

        // Determine actual scores (1 for win, 0 for loss)
        $homeActual = $game->home_score > $game->away_score ? 1 : 0;
        $awayActual = 1 - $homeActual;

        // Calculate K-factor with margin of victory and playoff multiplier
        $kFactor = $this->calculateKFactor($game);

        // Calculate Elo changes
        $homeChange = round($kFactor * ($homeActual - $homeExpected), 1);
        $awayChange = round($kFactor * ($awayActual - $awayExpected), 1);

        // Update team Elo ratings
        $newHomeElo = round($homeElo + $homeChange);
        $newAwayElo = round($awayElo + $awayChange);

        $homeTeam->update(['elo_rating' => $newHomeElo]);
        $awayTeam->update(['elo_rating' => $newAwayElo]);

        // Save Elo history
        $this->saveEloHistory($homeTeam, $game, $newHomeElo, $homeChange);
        $this->saveEloHistory($awayTeam, $game, $newAwayElo, $awayChange);

        return [
            'home_change' => $homeChange,
            'away_change' => $awayChange,
            'home_new_elo' => $newHomeElo,
            'away_new_elo' => $newAwayElo,
            'skipped' => false,
        ];
    }

    /**
     * Check if Elo has already been calculated for this game
     */
    protected function eloAlreadyCalculated(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        $eloRatingClass = $this->getEloRatingModel();

        return $eloRatingClass::query()
            ->where('game_id', $game->id)
            ->where(function ($query) use ($homeTeam, $awayTeam) {
                $query->where('team_id', $homeTeam->id)
                    ->orWhere('team_id', $awayTeam->id);
            })
            ->exists();
    }

    /**
     * Calculate expected score using Elo formula
     */
    protected function calculateExpectedScore(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    /**
     * Save Elo history record
     */
    protected function saveEloHistory(Model $team, Model $game, int $newElo, float $eloChange): void
    {
        $eloRatingClass = $this->getEloRatingModel();

        $eloRatingClass::create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'season' => $game->season,
            'week' => $game->week ?? null,
            'date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
        ]);
    }
}
