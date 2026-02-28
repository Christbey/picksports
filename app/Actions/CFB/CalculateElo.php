<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\CFB\EloRating;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'cfb';

    protected const ELO_RATING_MODEL = EloRating::class;

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('cfb.elo.base_k_factor');

        $kFactor = $this->applyRecencyWeekMultiplier(
            $game,
            (float) $kFactor,
            config('cfb.season.types.regular')
        );

        $kFactor = $this->applyPlayoffMultiplier($game, (float) $kFactor);

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
        $coefficient = config('cfb.elo.mov_coefficient');
        $maxMultiplier = config('cfb.elo.max_mov_multiplier');

        return $this->resolveLogMarginMultiplier($margin, (float) $coefficient, (float) $maxMultiplier);
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
