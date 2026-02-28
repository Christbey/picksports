<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractEloCalculator
{
    protected const SPORT_KEY = '';

    protected const ELO_RATING_MODEL = Model::class;

    /**
     * Get the sport identifier for config lookups (e.g., 'nba', 'nfl')
     */
    protected function getSport(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on elo calculator.');
        }

        return static::SPORT_KEY;
    }

    /**
     * Get the EloRating model class for this sport
     */
    protected function getEloRatingModel(): string
    {
        if (static::ELO_RATING_MODEL === Model::class) {
            throw new \RuntimeException('ELO_RATING_MODEL must be defined on elo calculator.');
        }

        return static::ELO_RATING_MODEL;
    }

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

    protected function applyPlayoffMultiplier(Model $game, float $value): float
    {
        if (! $this->isPlayoffGame($game)) {
            return $value;
        }

        $sport = $this->getSport();

        return $value * (float) config("{$sport}.elo.playoff_multiplier", 1.0);
    }

    protected function calculateStandardKFactor(Model $game, string $baseKey = 'base_k_factor'): float
    {
        $sport = $this->getSport();
        $kFactor = (float) config("{$sport}.elo.{$baseKey}");
        $kFactor = $this->applyPlayoffMultiplier($game, $kFactor);
        $kFactor *= $this->calculateMarginMultiplier($game);

        return $kFactor;
    }

    protected function applyRecencyWeekMultiplier(Model $game, float $kFactor, mixed $regularSeasonType): float
    {
        $sport = $this->getSport();
        $week = (int) ($game->week ?? 0);
        $recencyWeeks = (int) config("{$sport}.elo.recency_weeks", 0);

        if ($week < 1 || $week > $recencyWeeks || $game->season_type != $regularSeasonType) {
            return $kFactor;
        }

        return $kFactor * (float) config("{$sport}.elo.recency_multiplier", 1.0);
    }

    protected function resolveLogMarginMultiplier(int $margin, float $coefficient, float $maxMultiplier): float
    {
        return min($maxMultiplier, 1.0 + (log($margin + 1) * $coefficient));
    }

    /**
     * Resolve margin-of-victory multiplier from configured tiers.
     *
     * @param  array<int, array{max_margin:int|null,multiplier:float|int}>  $tiers
     */
    protected function resolveMarginMultiplier(int $margin, array $tiers): float
    {
        foreach ($tiers as $tier) {
            if (($tier['max_margin'] ?? null) === null || $margin <= (int) $tier['max_margin']) {
                return (float) ($tier['multiplier'] ?? 1.0);
            }
        }

        return 1.0;
    }

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
        $kFactor *= $this->calculateSosAdjustment($homeElo, $awayElo);

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
     * Calculate strength-of-schedule adjustment to dampen K-factor for mismatched games.
     * Override in sport-specific subclasses to enable.
     */
    protected function calculateSosAdjustment(int $homeElo, int $awayElo): float
    {
        return 1.0;
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
            'game_date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
        ]);
    }
}
