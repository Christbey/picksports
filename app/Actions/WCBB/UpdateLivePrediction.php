<?php

namespace App\Actions\WCBB;

use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use Carbon\Carbon;

class UpdateLivePrediction
{
    private const TOTAL_GAME_SECONDS = 2400; // 2 halves * 20 minutes * 60 seconds

    private const SECONDS_PER_HALF = 1200; // 20 minutes

    /**
     * Update live prediction data for an in-progress game.
     *
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_seconds_remaining: int}|null
     */
    public function execute(Game $game): ?array
    {
        if (! $this->isGameInProgress($game)) {
            return null;
        }

        $prediction = $game->prediction;

        if (! $prediction) {
            return null;
        }

        $secondsRemaining = $this->calculateSecondsRemaining($game->period, $game->game_clock);
        $secondsElapsed = self::TOTAL_GAME_SECONDS - $secondsRemaining;
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalPoints = ($game->home_score ?? 0) + ($game->away_score ?? 0);

        // Calculate live win probability
        $liveWinProbability = $this->calculateLiveWinProbability(
            $margin,
            $secondsRemaining,
            $prediction->win_probability ?? 0.5
        );

        // Calculate live predicted spread (expected final margin)
        $livePredictedSpread = $this->calculateLiveSpread(
            $margin,
            $secondsRemaining,
            $prediction->predicted_spread ?? 0
        );

        // Calculate live predicted total (expected final total points)
        $livePredictedTotal = $this->calculateLiveTotal(
            $totalPoints,
            $secondsElapsed,
            $secondsRemaining,
            $prediction->predicted_total ?? 140
        );

        // Update the prediction record
        $prediction->update([
            'live_predicted_spread' => round($livePredictedSpread, 1),
            'live_win_probability' => round($liveWinProbability, 3),
            'live_predicted_total' => round($livePredictedTotal, 1),
            'live_seconds_remaining' => $secondsRemaining,
            'live_updated_at' => Carbon::now(),
        ]);

        return [
            'live_predicted_spread' => round($livePredictedSpread, 1),
            'live_win_probability' => round($liveWinProbability, 3),
            'live_predicted_total' => round($livePredictedTotal, 1),
            'live_seconds_remaining' => $secondsRemaining,
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

        // WCBB uses halves (1 = first half, 2 = second half)
        $halvesRemaining = max(0, 2 - $period);
        $secondsFromHalves = $halvesRemaining * self::SECONDS_PER_HALF;

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 2) {
            return $secondsFromHalves + $clockSeconds;
        }

        // Overtime - treat as 5 minutes remaining max
        return min($clockSeconds, 300);
    }

    protected function parseGameClock(?string $gameClock): int
    {
        if (! $gameClock) {
            return 0;
        }

        $parts = explode(':', $gameClock);

        if (count($parts) !== 2) {
            return 0;
        }

        $minutes = (int) $parts[0];
        $seconds = (int) $parts[1];

        return ($minutes * 60) + $seconds;
    }

    /**
     * Calculate live win probability based on margin and time remaining.
     */
    protected function calculateLiveWinProbability(int $margin, int $secondsRemaining, float $preGameProbability): float
    {
        if ($secondsRemaining <= 0) {
            if ($margin > 0) {
                return 0.999;
            }
            if ($margin < 0) {
                return 0.001;
            }

            return 0.5;
        }

        $timeElapsedFraction = 1 - ($secondsRemaining / self::TOTAL_GAME_SECONDS);

        // Points are worth more as game progresses
        $pointValue = 0.02 + (0.15 * pow($timeElapsedFraction, 2));

        $marginAdjustment = $margin * $pointValue;

        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));

        $preGameWeight = 1 - pow($timeElapsedFraction, 0.5);

        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        $probability = 1 / (1 + exp(-$combinedLogOdds));

        return max(0.001, min(0.999, $probability));
    }

    /**
     * Calculate live predicted spread (expected final margin).
     *
     * The live spread represents the expected final margin from the current game state.
     * It blends the pre-game prediction with the current in-game performance.
     */
    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $timeElapsedFraction = 1 - ($secondsRemaining / self::TOTAL_GAME_SECONDS);

        // Calculate expected remaining margin based on pace
        $remainingMinutes = $secondsRemaining / 60;

        // Expected additional margin contribution from pre-game prediction
        // Scales down as game progresses
        $remainingPreGameContribution = $preGameSpread * (1 - $timeElapsedFraction);

        // Current pace margin: extrapolate current margin to full game
        // But weight it based on how much of the game has been played
        $currentPaceMargin = $timeElapsedFraction > 0
            ? ($currentMargin / $timeElapsedFraction) * (1 - $timeElapsedFraction)
            : 0;

        // Blend current margin with remaining expected margin
        // As game progresses, current margin dominates
        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $timeElapsedFraction))
                                      + ($currentPaceMargin * $timeElapsedFraction * 0.3);

        // Apply regression toward current margin as game progresses
        // Late in the game, the live spread should be very close to current margin
        $regressionWeight = pow($timeElapsedFraction, 2);

        return ($liveSpread * (1 - $regressionWeight)) + ($currentMargin * $regressionWeight);
    }

    /**
     * Calculate live predicted total (expected final total points).
     *
     * Projects final total based on current scoring pace and pre-game prediction.
     */
    protected function calculateLiveTotal(int $currentTotal, int $secondsElapsed, int $secondsRemaining, float $preGameTotal): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($secondsElapsed <= 0) {
            return $preGameTotal;
        }

        $timeElapsedFraction = $secondsElapsed / self::TOTAL_GAME_SECONDS;

        // Calculate current scoring pace (points per second)
        $currentPace = $currentTotal / $secondsElapsed;

        // Project final total based on current pace
        $pacePredictedTotal = $currentPace * self::TOTAL_GAME_SECONDS;

        // Pre-game total prediction for remaining time
        $remainingPreGamePoints = $preGameTotal * (1 - $timeElapsedFraction);

        // Blend pace-based projection with pre-game prediction
        // Early in game: weight pre-game more
        // Late in game: weight pace more
        $paceWeight = pow($timeElapsedFraction, 0.7);

        $projectedRemaining = ($pacePredictedTotal - $currentTotal) * $paceWeight
                            + $remainingPreGamePoints * (1 - $paceWeight);

        $liveTotal = $currentTotal + max(0, $projectedRemaining);

        // Apply bounds (WCBB games typically score between 100-180 total points)
        return max($currentTotal, min(220, $liveTotal));
    }

    /**
     * Clear live prediction data (for completed games).
     */
    public function clearLivePrediction(Prediction $prediction): void
    {
        $prediction->update([
            'live_predicted_spread' => null,
            'live_win_probability' => null,
            'live_predicted_total' => null,
            'live_seconds_remaining' => null,
            'live_updated_at' => null,
        ]);
    }
}
