<?php

namespace App\Actions\NBA;

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use Carbon\Carbon;

class UpdateLivePrediction
{
    private const TOTAL_GAME_SECONDS = 2880; // 4 quarters * 12 minutes * 60 seconds

    private const SECONDS_PER_QUARTER = 720; // 12 minutes

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
            $prediction->predicted_total ?? 220
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

        $quartersRemaining = max(0, 4 - $period);
        $secondsFromQuarters = $quartersRemaining * self::SECONDS_PER_QUARTER;

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 4) {
            return $secondsFromQuarters + $clockSeconds;
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

        if ($period <= 4) {
            // Regulation: completed quarters + elapsed time in current quarter
            $completedQuarters = $period - 1;
            $elapsedInCurrentQuarter = self::SECONDS_PER_QUARTER - $clockSeconds;

            return ($completedQuarters * self::SECONDS_PER_QUARTER) + $elapsedInCurrentQuarter;
        }

        // Overtime: all regulation (2880) + completed OT periods + elapsed in current OT
        $completedOtPeriods = $period - 5; // period 5 = OT1, period 6 = OT2, etc.
        $elapsedInCurrentOt = self::SECONDS_PER_OT - min($clockSeconds, self::SECONDS_PER_OT);

        return self::TOTAL_GAME_SECONDS + ($completedOtPeriods * self::SECONDS_PER_OT) + $elapsedInCurrentOt;
    }

    /**
     * Calculate effective total game length based on current period.
     * In regulation this is 2880 seconds. In OT, extends by 300 per OT period.
     */
    protected function calculateEffectiveGameLength(int $period): int
    {
        if ($period <= 4) {
            return self::TOTAL_GAME_SECONDS;
        }

        // In OT: regulation + all OT periods through the current one
        $otPeriods = $period - 4;

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

        // Blowout compression: push extreme margins toward certainty late in the game.
        // A 20+ point margin in the 4th quarter should produce 99%+ probability.
        $absMargin = abs($margin);
        if ($absMargin >= 15 && $timeElapsedFraction >= 0.5) {
            // Scale factor grows with both margin size and time elapsed
            $blowoutIntensity = (($absMargin - 15) / 15) * pow($timeElapsedFraction, 2);
            $blowoutBoost = 1 - exp(-2.5 * $blowoutIntensity);

            if ($margin > 0) {
                $probability = $probability + ((0.999 - $probability) * $blowoutBoost);
            } else {
                $probability = $probability - (($probability - 0.001) * $blowoutBoost);
            }
        }

        return max(0.001, min(0.999, $probability));
    }

    /**
     * Calculate live predicted spread (expected final margin).
     *
     * Blends the pre-game spread with the current margin proportionally to time elapsed.
     * Early in the game, the pre-game spread dominates. Late in the game, the current
     * margin dominates. A pace-based projection adds a small data-driven adjustment
     * that grows with sample size.
     */
    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        // Weight current evidence with a power curve so pre-game spread
        // dominates through Q1, transitions mid-game, and current margin takes over in Q4
        $currentWeight = pow($timeElapsedFraction, 1.5);
        $preGameWeight = 1 - $currentWeight;

        // Current-evidence projection: blend the raw margin with the pace extrapolation.
        // Early in the game, trust the raw margin over the noisy pace projection.
        // paceCredibility ramps from 0 → 1 as sample size grows.
        $paceCredibility = pow($timeElapsedFraction, 2);
        $paceProjectedMargin = $timeElapsedFraction > 0
            ? $currentMargin / $timeElapsedFraction
            : (float) $currentMargin;
        $currentEvidence = ($currentMargin * (1 - $paceCredibility)) + ($paceProjectedMargin * $paceCredibility);

        // Blend pre-game spread with current evidence
        $blendedProjection = ($preGameSpread * $preGameWeight) + ($currentEvidence * $currentWeight);

        // Late in the game, converge fully to the raw current margin
        $lateGameConvergence = pow($timeElapsedFraction, 3);

        return ($blendedProjection * (1 - $lateGameConvergence)) + ($currentMargin * $lateGameConvergence);
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

        // Dynamic upper bound: 300 for regulation, +25 per OT period
        $upperBound = 300;
        if ($period > 4) {
            $otPeriods = $period - 4;
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
