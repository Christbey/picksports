<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractAdvancedBasketballUpdateLivePrediction;

class UpdateLivePrediction extends AbstractAdvancedBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2400;

    protected const REGULATION_PERIODS = 2;

    protected const SECONDS_PER_PERIOD = 1200;

    protected const DEFAULT_PRE_GAME_TOTAL = 140;

    protected const UPPER_BOUND_BASE = 220;

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

        $pointValue = 0.02 + (0.15 * pow($timeElapsedFraction, 2));
        $marginAdjustment = $margin * $pointValue;

        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));
        $preGameWeight = 1 - pow($timeElapsedFraction, 0.5);
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        $probability = 1 / (1 + exp(-$combinedLogOdds));

        return max(0.001, min(0.999, $probability));
    }

    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $remainingFraction = 1 - $timeElapsedFraction;
        $remainingPreGameContribution = $preGameSpread * $remainingFraction;

        $currentPaceMargin = $timeElapsedFraction > 0
            ? ($currentMargin / $timeElapsedFraction) * $remainingFraction
            : 0;

        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $timeElapsedFraction * 0.5))
            + ($currentPaceMargin * $timeElapsedFraction * 0.3);

        $regressionWeight = pow($timeElapsedFraction, 2);

        return ($liveSpread * (1 - $regressionWeight)) + ($currentMargin * $regressionWeight);
    }
}
