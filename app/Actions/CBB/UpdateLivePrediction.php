<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use Carbon\Carbon;

class UpdateLivePrediction
{
    private const TOTAL_GAME_SECONDS = 2400; // 2 halves * 20 minutes * 60 seconds

    private const SECONDS_PER_HALF = 1200; // 20 minutes

    private const SECONDS_PER_OT = 300; // 5 minutes

    /**
     * Update live prediction data for an in-progress game.
     * Clears live prediction data when the game is no longer in progress.
     *
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_seconds_remaining: int}|null
     */
    public function execute(Game $game): ?array
    {
        if (! $this->isGameInProgress($game)) {
            // Clear stale live data when game is no longer in progress
            $prediction = $game->prediction;
            if ($prediction && $prediction->live_seconds_remaining !== null) {
                $this->clearLivePrediction($prediction);
            }

            return null;
        }

        $prediction = $game->prediction;

        if (! $prediction) {
            return null;
        }

        $secondsRemaining = $this->calculateSecondsRemaining($game->period, $game->game_clock);
        $actualSecondsElapsed = $this->calculateActualSecondsElapsed($game->period, $game->game_clock);
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalPoints = ($game->home_score ?? 0) + ($game->away_score ?? 0);

        // For time-fraction calculations, use regulation total as the baseline
        // but account for OT by allowing fractions > 1.0 to be clamped
        $effectiveGameLength = $this->calculateEffectiveGameLength($game->period);
        $timeElapsedFraction = min(1.0, $actualSecondsElapsed / $effectiveGameLength);

        // Calculate live win probability
        $liveWinProbability = $this->calculateLiveWinProbability(
            $margin,
            $secondsRemaining,
            $timeElapsedFraction,
            $prediction->win_probability ?? 0.5
        );

        // Calculate live predicted spread (expected final margin)
        $livePredictedSpread = $this->calculateLiveSpread(
            $margin,
            $secondsRemaining,
            $timeElapsedFraction,
            $prediction->predicted_spread ?? 0
        );

        // Calculate live predicted total (expected final total points)
        $livePredictedTotal = $this->calculateLiveTotal(
            $totalPoints,
            $actualSecondsElapsed,
            $secondsRemaining,
            $effectiveGameLength,
            $game->period,
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
            'STATUS_SUSPENDED',
        ];

        return in_array($game->status, $inProgressStatuses) && $game->period >= 1;
    }

    protected function calculateSecondsRemaining(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return self::TOTAL_GAME_SECONDS;
        }

        // CBB uses halves (1 = first half, 2 = second half)
        $halvesRemaining = max(0, 2 - $period);
        $secondsFromHalves = $halvesRemaining * self::SECONDS_PER_HALF;

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 2) {
            return $secondsFromHalves + $clockSeconds;
        }

        // Overtime - only the current OT period's clock matters
        return min($clockSeconds, self::SECONDS_PER_OT);
    }

    /**
     * Calculate actual seconds elapsed including completed OT periods.
     */
    protected function calculateActualSecondsElapsed(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return 0;
        }

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 2) {
            // Regulation: completed halves + elapsed time in current half
            $completedHalves = $period - 1;
            $elapsedInCurrentHalf = self::SECONDS_PER_HALF - $clockSeconds;

            return ($completedHalves * self::SECONDS_PER_HALF) + $elapsedInCurrentHalf;
        }

        // Overtime: all regulation (2400) + completed OT periods + elapsed in current OT
        $completedOtPeriods = $period - 3; // period 3 = OT1, period 4 = OT2, etc.
        $elapsedInCurrentOt = self::SECONDS_PER_OT - min($clockSeconds, self::SECONDS_PER_OT);

        return self::TOTAL_GAME_SECONDS + ($completedOtPeriods * self::SECONDS_PER_OT) + $elapsedInCurrentOt;
    }

    /**
     * Calculate effective total game length based on current period.
     * In regulation this is 2400 seconds. In OT, extends by 300 per OT period.
     */
    protected function calculateEffectiveGameLength(int $period): int
    {
        if ($period <= 2) {
            return self::TOTAL_GAME_SECONDS;
        }

        // In OT: regulation + all OT periods through the current one
        $otPeriods = $period - 2;

        return self::TOTAL_GAME_SECONDS + ($otPeriods * self::SECONDS_PER_OT);
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
    protected function calculateLiveWinProbability(int $margin, int $secondsRemaining, float $timeElapsedFraction, float $preGameProbability): float
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
    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $remainingFraction = 1 - $timeElapsedFraction;

        // Expected additional margin contribution from pre-game prediction
        // Scales down linearly as game progresses (single discount)
        $remainingPreGameContribution = $preGameSpread * $remainingFraction;

        // Current pace margin: extrapolate current margin to remaining time
        $currentPaceMargin = $timeElapsedFraction > 0
            ? ($currentMargin / $timeElapsedFraction) * $remainingFraction
            : 0;

        // Blend current margin with remaining expected margin
        // Pre-game contribution weighted linearly, pace contribution grows with time
        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $timeElapsedFraction * 0.5))
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
    protected function calculateLiveTotal(int $currentTotal, int $actualSecondsElapsed, int $secondsRemaining, int $effectiveGameLength, int $period, float $preGameTotal): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($actualSecondsElapsed <= 0) {
            return $preGameTotal;
        }

        $timeElapsedFraction = $actualSecondsElapsed / $effectiveGameLength;

        // Calculate current scoring pace (points per second) using actual elapsed time
        $currentPace = $currentTotal / $actualSecondsElapsed;

        // Project final total based on current pace over the effective game length
        $pacePredictedTotal = $currentPace * $effectiveGameLength;

        // Pre-game total prediction for remaining time
        $remainingPreGamePoints = $preGameTotal * (1 - $timeElapsedFraction);

        // Blend pace-based projection with pre-game prediction
        // Early in game: weight pre-game more
        // Late in game: weight pace more
        $paceWeight = pow($timeElapsedFraction, 0.7);

        $projectedRemaining = ($pacePredictedTotal - $currentTotal) * $paceWeight
                            + $remainingPreGamePoints * (1 - $paceWeight);

        $liveTotal = $currentTotal + max(0, $projectedRemaining);

        // Dynamic upper bound: 220 for regulation, +25 per OT period
        $upperBound = 220;
        if ($period > 2) {
            $otPeriods = $period - 2;
            $upperBound += $otPeriods * 25;
        }

        return max($currentTotal, min($upperBound, $liveTotal));
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
