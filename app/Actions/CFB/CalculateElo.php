<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\CFB\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected function getSport(): string
    {
        return 'cfb';
    }

    protected function getEloRatingModel(): string
    {
        return EloRating::class;
    }

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('cfb.elo.base_k_factor');

        // Increase K-factor early in season (more volatility as teams find identity)
        if ($game->week && $game->week <= config('cfb.elo.recency_weeks') && $game->season_type == config('cfb.season.types.regular')) {
            $kFactor *= config('cfb.elo.recency_multiplier');
        }

        // Apply playoff multiplier (bowl games and CFP)
        if ($this->isPlayoffGame($game)) {
            $kFactor *= config('cfb.elo.playoff_multiplier');
        }

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $game->season_type == config('cfb.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);

        // Logarithmic formula with diminishing returns
        // Formula: 1.0 + (log(margin + 1) * coefficient)
        // This prevents blowouts from dominating too much
        $coefficient = config('cfb.elo.mov_coefficient');
        $maxMultiplier = config('cfb.elo.max_mov_multiplier');

        return min($maxMultiplier, 1.0 + (log($margin + 1) * $coefficient));
    }

    protected function saveEloHistory(Model $team, Model $game, int $newElo, float $eloChange): void
    {
        $eloRatingClass = $this->getEloRatingModel();

        $eloRatingClass::create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'season' => $game->season,
            'week' => $game->week,
            'season_type' => $game->season_type,
            'date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
        ]);
    }
}
