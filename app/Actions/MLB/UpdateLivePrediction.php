<?php

namespace App\Actions\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use Carbon\Carbon;

class UpdateLivePrediction
{
    private const TOTAL_INNINGS = 9;

    private const OUTS_PER_INNING = 6; // 3 per half-inning × 2

    private const TOTAL_OUTS = 54; // 9 innings × 6 outs per inning

    /**
     * Update live prediction data for an in-progress game.
     *
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_outs_remaining: int}|null
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

        $outsRemaining = $this->calculateOutsRemaining($game->inning, $game->inning_state);
        $outsElapsed = self::TOTAL_OUTS - $outsRemaining;
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalRuns = ($game->home_score ?? 0) + ($game->away_score ?? 0);

        // Calculate live win probability
        $liveWinProbability = $this->calculateLiveWinProbability(
            $margin,
            $outsRemaining,
            $game->inning_state,
            $prediction->win_probability ?? 0.5
        );

        // Calculate live predicted spread (expected final margin)
        $livePredictedSpread = $this->calculateLiveSpread(
            $margin,
            $outsRemaining,
            $prediction->predicted_spread ?? 0
        );

        // Calculate live predicted total (expected final total runs)
        $livePredictedTotal = $this->calculateLiveTotal(
            $totalRuns,
            $outsElapsed,
            $outsRemaining,
            $prediction->predicted_total ?? 8
        );

        // Update the prediction record
        $prediction->update([
            'live_predicted_spread' => round($livePredictedSpread, 1),
            'live_win_probability' => round($liveWinProbability, 3),
            'live_predicted_total' => round($livePredictedTotal, 1),
            'live_outs_remaining' => $outsRemaining,
            'live_updated_at' => Carbon::now(),
        ]);

        return [
            'live_predicted_spread' => round($livePredictedSpread, 1),
            'live_win_probability' => round($liveWinProbability, 3),
            'live_predicted_total' => round($livePredictedTotal, 1),
            'live_outs_remaining' => $outsRemaining,
        ];
    }

    protected function isGameInProgress(Game $game): bool
    {
        $inProgressStatuses = [
            'STATUS_IN_PROGRESS',
            'STATUS_DELAYED',
        ];

        return in_array($game->status, $inProgressStatuses) && $game->inning >= 1;
    }

    protected function calculateOutsRemaining(?int $inning, ?string $inningState): int
    {
        if (! $inning || $inning < 1) {
            return self::TOTAL_OUTS;
        }

        // Calculate outs remaining based on inning and inning state (top/bottom)
        $inningsRemaining = max(0, self::TOTAL_INNINGS - $inning);
        $outsFromInnings = $inningsRemaining * self::OUTS_PER_INNING;

        // If in top of inning, 3 outs remaining in top + 3 in bottom
        // If in bottom of inning, only 3 outs remaining in bottom
        if ($inningState === 'top') {
            $outsFromInnings += 6; // This inning: 3 (current top) + 3 (upcoming bottom)
        } elseif ($inningState === 'bottom') {
            $outsFromInnings += 3; // This inning: 3 (current bottom)
        }

        // For extra innings (beyond 9th)
        if ($inning > self::TOTAL_INNINGS) {
            // In extras, assume max 6 outs remaining (current inning)
            if ($inningState === 'top') {
                return 6;
            } elseif ($inningState === 'bottom') {
                return 3;
            }
        }

        return max(0, $outsFromInnings);
    }

    /**
     * Calculate live win probability based on margin and outs remaining.
     */
    protected function calculateLiveWinProbability(int $margin, int $outsRemaining, ?string $inningState, float $preGameProbability): float
    {
        if ($outsRemaining <= 0) {
            if ($margin > 0) {
                return 0.999;
            }
            if ($margin < 0) {
                return 0.001;
            }

            return 0.5;
        }

        $outsElapsedFraction = 1 - ($outsRemaining / self::TOTAL_OUTS);

        // In baseball, runs are worth more as game progresses
        $runValue = 0.08 + (0.25 * pow($outsElapsedFraction, 2));

        // Bottom of 9th with lead is very significant
        if ($inningState === 'bottom' && $outsRemaining <= 3 && $margin > 0) {
            $runValue *= 2.0; // Double the value in walk-off situations
        }

        $marginAdjustment = $margin * $runValue;

        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));

        $preGameWeight = 1 - pow($outsElapsedFraction, 0.4);

        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        $probability = 1 / (1 + exp(-$combinedLogOdds));

        return max(0.001, min(0.999, $probability));
    }

    /**
     * Calculate live predicted spread (expected final margin).
     */
    protected function calculateLiveSpread(int $currentMargin, int $outsRemaining, float $preGameSpread): float
    {
        if ($outsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $outsElapsedFraction = 1 - ($outsRemaining / self::TOTAL_OUTS);

        // Expected remaining margin contribution from pre-game prediction
        $remainingPreGameContribution = $preGameSpread * (1 - $outsElapsedFraction);

        // Current pace margin
        $currentPaceMargin = $outsElapsedFraction > 0
            ? ($currentMargin / $outsElapsedFraction) * (1 - $outsElapsedFraction)
            : 0;

        // Blend current margin with remaining expected margin
        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $outsElapsedFraction))
                                      + ($currentPaceMargin * $outsElapsedFraction * 0.2);

        // Apply regression toward current margin as game progresses
        $regressionWeight = pow($outsElapsedFraction, 2);

        return ($liveSpread * (1 - $regressionWeight)) + ($currentMargin * $regressionWeight);
    }

    /**
     * Calculate live predicted total (expected final total runs).
     */
    protected function calculateLiveTotal(int $currentTotal, int $outsElapsed, int $outsRemaining, float $preGameTotal): float
    {
        if ($outsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($outsElapsed <= 0) {
            return $preGameTotal;
        }

        $outsElapsedFraction = $outsElapsed / self::TOTAL_OUTS;

        // Calculate current scoring pace (runs per out)
        $currentPace = $currentTotal / $outsElapsed;

        // Project final total based on current pace
        $pacePredictedTotal = $currentPace * self::TOTAL_OUTS;

        // Pre-game total prediction for remaining outs
        $remainingPreGameRuns = $preGameTotal * (1 - $outsElapsedFraction);

        // Blend pace-based projection with pre-game prediction
        $paceWeight = pow($outsElapsedFraction, 0.6);

        $projectedRemaining = ($pacePredictedTotal - $currentTotal) * $paceWeight
                            + $remainingPreGameRuns * (1 - $paceWeight);

        $liveTotal = $currentTotal + max(0, $projectedRemaining);

        // Apply bounds (MLB games typically score between 4-15 total runs)
        return max($currentTotal, min(25, $liveTotal));
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
            'live_outs_remaining' => null,
            'live_updated_at' => null,
        ]);
    }
}
