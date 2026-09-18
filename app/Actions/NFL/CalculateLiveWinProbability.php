<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;

class CalculateLiveWinProbability
{
    /**
     * Calculate live win probability for the home team based on current game state.
     *
     * @return array{home_win_probability: float, away_win_probability: float, is_live: bool, seconds_remaining: int, margin: int}|null
     */
    public function execute(Game $game): ?array
    {
        $state = LiveGameState::fromGame($game);
        if ($state === null) {
            return null;
        }

        $secondsRemaining = $state['seconds_remaining'];
        $margin = $state['margin'];

        $preGameProbability = $game->prediction?->win_probability ?? 0.5;

        $homeWinProbability = LiveGameState::homeProbability(
            $margin,
            $secondsRemaining,
            $preGameProbability,
            $game->period > 4
        );

        return [
            'home_win_probability' => round($homeWinProbability, 3),
            'away_win_probability' => round(1 - $homeWinProbability, 3),
            'is_live' => true,
            'seconds_remaining' => $secondsRemaining,
            'margin' => $margin,
        ];
    }
}
