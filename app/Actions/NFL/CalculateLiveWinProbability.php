<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;

class CalculateLiveWinProbability
{
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
        $inProgressStatuses = [
            'STATUS_IN_PROGRESS',
            'STATUS_HALFTIME',
            'STATUS_END_PERIOD',
        ];

        return in_array($game->status, $inProgressStatuses) && $game->period >= 1;
    }

    protected function calculateSecondsRemaining(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return self::TOTAL_GAME_SECONDS;
        }

        $quartersRemaining = max(0, 4 - $period);
        $secondsFromQuarters = $quartersRemaining * self::SECONDS_PER_QUARTER;

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 4) {
            return $secondsFromQuarters + $clockSeconds;
        }

        // Overtime - treat as 10 minutes remaining max
        return min($clockSeconds, 600);
    }

    protected function parseGameClock(?string $gameClock): int
    {
        if (! $gameClock) {
            return 0;
        }

        // Parse clock format like "12:30" or "0:45"
        $parts = explode(':', $gameClock);

        if (count($parts) !== 2) {
            return 0;
        }

        $minutes = (int) $parts[0];
        $seconds = (int) $parts[1];

        return ($minutes * 60) + $seconds;
    }

    protected function calculateProbability(int $margin, int $secondsRemaining, float $preGameProbability): float
    {
        // If game is over, return based on margin
        if ($secondsRemaining <= 0) {
            if ($margin > 0) {
                return 0.999;
            }
            if ($margin < 0) {
                return 0.001;
            }

            return 0.5;
        }

        // Calculate time elapsed fraction (0 to 1)
        $timeElapsedFraction = 1 - ($secondsRemaining / self::TOTAL_GAME_SECONDS);

        // Points are worth more as game progresses
        // At game start, 1 point ~ 2% probability shift
        // At game end, 1 point ~ 10%+ probability shift
        $pointValue = 0.02 + (0.15 * pow($timeElapsedFraction, 2));

        // Calculate margin-based adjustment
        $marginAdjustment = $margin * $pointValue;

        // Convert pre-game probability to log odds
        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));

        // Weight pre-game odds less as game progresses
        $preGameWeight = 1 - pow($timeElapsedFraction, 0.5);

        // Combine pre-game probability with in-game state
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        // Convert back to probability using sigmoid
        $probability = 1 / (1 + exp(-$combinedLogOdds));

        // Clamp to reasonable bounds
        return max(0.001, min(0.999, $probability));
    }
}
