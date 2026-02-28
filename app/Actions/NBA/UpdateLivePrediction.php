<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractAdvancedBasketballUpdateLivePrediction;

class UpdateLivePrediction extends AbstractAdvancedBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2880;

    protected const REGULATION_PERIODS = 4;

    protected const SECONDS_PER_PERIOD = 720;

    protected const DEFAULT_PRE_GAME_TOTAL = 220;

    protected const UPPER_BOUND_BASE = 300;

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

        $absMargin = abs($margin);
        if ($absMargin >= 15 && $timeElapsedFraction >= 0.5) {
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

    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $currentWeight = pow($timeElapsedFraction, 1.5);
        $preGameWeight = 1 - $currentWeight;

        $paceCredibility = pow($timeElapsedFraction, 2);
        $paceProjectedMargin = $timeElapsedFraction > 0
            ? $currentMargin / $timeElapsedFraction
            : (float) $currentMargin;
        $currentEvidence = ($currentMargin * (1 - $paceCredibility)) + ($paceProjectedMargin * $paceCredibility);

        $blendedProjection = ($preGameSpread * $preGameWeight) + ($currentEvidence * $currentWeight);
        $lateGameConvergence = pow($timeElapsedFraction, 3);

        return ($blendedProjection * (1 - $lateGameConvergence)) + ($currentMargin * $lateGameConvergence);
    }
}
