<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractAdvancedBasketballUpdateLivePrediction;
use Carbon\Carbon;

class UpdateLivePrediction extends AbstractAdvancedBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2400;

    protected const REGULATION_PERIODS = 2;

    protected const SECONDS_PER_PERIOD = 1200;

    protected const DEFAULT_PRE_GAME_TOTAL = 140;

    protected const UPPER_BOUND_BASE = 220;

    /**
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_seconds_remaining: int}|null
     */
    public function execute(object $game): ?array
    {
        if (! $this->isGameInProgress($game)) {
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
        $effectiveGameLength = $this->calculateEffectiveGameLength($game->period);
        $timeElapsedFraction = min(1.0, $actualSecondsElapsed / $effectiveGameLength);
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalPoints = ($game->home_score ?? 0) + ($game->away_score ?? 0);

        $liveWinProbability = $this->calculateLiveWinProbability(
            $margin,
            $secondsRemaining,
            $timeElapsedFraction,
            $prediction->win_probability ?? 0.5
        );

        $livePredictedSpread = $this->calculateLiveSpread(
            $margin,
            $secondsRemaining,
            $timeElapsedFraction,
            $prediction->predicted_spread ?? 0
        );

        $livePredictedTotal = $this->calculateCbbLiveTotal(
            $totalPoints,
            $actualSecondsElapsed,
            $secondsRemaining,
            $effectiveGameLength,
            $game->period,
            $margin,
            $prediction->predicted_total ?? static::DEFAULT_PRE_GAME_TOTAL
        );

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

        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));
        $remainingTimeRatio = max(0.02, min(1.0, $secondsRemaining / static::TOTAL_GAME_SECONDS));
        $preGameWeight = pow($remainingTimeRatio, 0.65);
        $marginScale = 2.8 + (9.0 * $remainingTimeRatio);
        $marginAdjustment = $margin / $marginScale;
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        $probability = 1 / (1 + exp(-$combinedLogOdds));

        if ($secondsRemaining <= 90 && abs($margin) >= 2) {
            $lateLeadBoost = min(0.14, (abs($margin) / 12) * 0.14);
            $probability = $margin > 0
                ? $probability + ((0.999 - $probability) * $lateLeadBoost)
                : $probability - (($probability - 0.001) * $lateLeadBoost);
        }

        return max(0.001, min(0.999, $probability));
    }

    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $remainingFraction = max(0.0, min(1.0, $secondsRemaining / static::TOTAL_GAME_SECONDS));
        $scoreStateDampener = max(0.2, 1 - (abs($currentMargin) / 18));
        $pregameCarryWeight = (0.65 + (0.25 * $remainingFraction)) * $scoreStateDampener;
        $remainingPreGameContribution = $preGameSpread * $remainingFraction * $pregameCarryWeight;

        return $currentMargin + $remainingPreGameContribution;
    }

    private function calculateCbbLiveTotal(
        int $currentTotal,
        int $actualSecondsElapsed,
        int $secondsRemaining,
        int $effectiveGameLength,
        int $period,
        int $margin,
        float $preGameTotal
    ): float {
        if ($secondsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($actualSecondsElapsed <= 0) {
            return $preGameTotal;
        }

        $timeElapsedFraction = $actualSecondsElapsed / $effectiveGameLength;
        $actualRate = $currentTotal / max(1, $actualSecondsElapsed);
        $preGameRate = $preGameTotal / max(1, $effectiveGameLength);
        $actualWeight = pow($timeElapsedFraction, 0.8);
        $remainingRate = ($actualRate * $actualWeight) + ($preGameRate * (1 - $actualWeight));

        $scoreStateMultiplier = 1.0;
        $absMargin = abs($margin);
        if ($secondsRemaining <= 180 && $absMargin >= 10) {
            $scoreStateMultiplier -= 0.10;
        }

        // Tight late games trend faster because of fouls and deliberate quick possessions.
        if ($secondsRemaining <= 90 && $absMargin <= 8) {
            $scoreStateMultiplier += 0.10;
        }
        if ($secondsRemaining <= 45 && $absMargin <= 4) {
            $scoreStateMultiplier += 0.05;
        }

        $projectedRemaining = max(0.0, $remainingRate * $secondsRemaining * $scoreStateMultiplier);
        $liveTotal = $currentTotal + $projectedRemaining;

        $upperBound = static::UPPER_BOUND_BASE;
        if ($period > static::REGULATION_PERIODS) {
            $otPeriods = $period - static::REGULATION_PERIODS;
            $upperBound += $otPeriods * 25;
        }

        return max($currentTotal, min($upperBound, $liveTotal));
    }
}
