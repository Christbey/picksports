<?php

namespace App\Actions\NFL;

use App\Actions\Sports\Concerns\CalculatesFootballLiveProbability;
use App\Actions\Sports\Concerns\ManagesTimedLivePredictions;
use App\Models\NFL\Game;

class CalculateLiveWinProbability
{
    use CalculatesFootballLiveProbability;
    use ManagesTimedLivePredictions;

    private const TOTAL_GAME_SECONDS = 3600; // 4 quarters * 15 minutes * 60 seconds

    private const SECONDS_PER_QUARTER = 900; // 15 minutes

    /**
     * Calculate live win probability for the home team based on current game state.
     *
     * @return array{home_win_probability: float, away_win_probability: float, is_live: bool, seconds_remaining: int, margin: int}|null
     */
    public function execute(Game $game): ?array
    {
        if (! $this->isGameInProgress($game)) {
            return null;
        }

        $secondsRemaining = $this->calculateSecondsRemaining($game->period, $game->game_clock);
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);

        $preGameProbability = $game->prediction?->win_probability ?? 0.5;

        $homeWinProbability = $this->calculateProbability(
            $margin,
            $secondsRemaining,
            $preGameProbability
        );

        return [
            'home_win_probability' => round($homeWinProbability, 3),
            'away_win_probability' => round(1 - $homeWinProbability, 3),
            'is_live' => true,
            'seconds_remaining' => $secondsRemaining,
            'margin' => $margin,
        ];
    }

    protected function isGameInProgress(Game $game): bool
    {
        return $this->footballIsGameInProgress($game);
    }

    protected function calculateSecondsRemaining(int $period, ?string $gameClock): int
    {
        return $this->footballSecondsRemaining($period, $gameClock, self::TOTAL_GAME_SECONDS, self::SECONDS_PER_QUARTER, 600);
    }

    protected function calculateProbability(int $margin, int $secondsRemaining, float $preGameProbability): float
    {
        return $this->footballLiveWinProbability($margin, $secondsRemaining, $preGameProbability, self::TOTAL_GAME_SECONDS);
    }
}
