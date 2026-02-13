<?php

namespace App\Actions\WCBB;

use App\Models\WCBB\EloRating;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;

class CalculateElo
{
    public function execute(Game $game, bool $skipIfExists = true): array
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
        if ($skipIfExists) {
            $existingHistory = EloRating::query()
                ->where('game_id', $game->id)
                ->where(function ($query) use ($homeTeam, $awayTeam) {
                    $query->where('team_id', $homeTeam->id)
                        ->orWhere('team_id', $awayTeam->id);
                })
                ->exists();

            if ($existingHistory) {
                return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
            }
        }

        // Get current Elo ratings
        $defaultElo = config('wcbb.elo.default');
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Adjust for home court advantage
        $adjustedHomeElo = $homeElo + config('wcbb.elo.home_court_advantage');

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

    protected function calculateExpectedScore(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    protected function calculateKFactor(Game $game): float
    {
        $kFactor = config('wcbb.elo.base_k_factor');

        // Apply playoff multiplier (NCAA Tournament)
        if ($this->isPlayoffGame($game)) {
            $kFactor *= config('wcbb.elo.playoff_multiplier');
        }

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Game $game): bool
    {
        return $game->season_type === config('wcbb.season.types.postseason');
    }

    protected function calculateMarginMultiplier(Game $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('wcbb.elo.margin_multipliers');

        foreach ($multipliers as $tier) {
            if ($tier['max_margin'] === null || $margin <= $tier['max_margin']) {
                return $tier['multiplier'];
            }
        }

        return 1.0;
    }

    protected function saveEloHistory(Team $team, Game $game, int $newElo, float $eloChange): void
    {
        EloRating::create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'season' => $game->season,
            'game_date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
        ]);
    }
}
