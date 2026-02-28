<?php

namespace App\Actions\Sports\Concerns;

trait CalculatesFootballLiveProbability
{
    protected function footballIsGameInProgress(object $game): bool
    {
        $inProgressStatuses = [
            'STATUS_IN_PROGRESS',
            'STATUS_HALFTIME',
            'STATUS_END_PERIOD',
        ];

        return in_array($game->status, $inProgressStatuses, true) && $game->period >= 1;
    }

    protected function footballSecondsRemaining(
        int $period,
        ?string $gameClock,
        int $totalGameSeconds = 3600,
        int $secondsPerQuarter = 900,
        int $maxOvertimeSeconds = 600
    ): int {
        if ($period < 1) {
            return $totalGameSeconds;
        }

        $quartersRemaining = max(0, 4 - $period);
        $secondsFromQuarters = $quartersRemaining * $secondsPerQuarter;
        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 4) {
            return $secondsFromQuarters + $clockSeconds;
        }

        return min($clockSeconds, $maxOvertimeSeconds);
    }

    protected function footballLiveWinProbability(int $margin, int $secondsRemaining, float $preGameProbability, int $totalGameSeconds = 3600): float
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

        $timeElapsedFraction = 1 - ($secondsRemaining / $totalGameSeconds);
        $pointValue = 0.02 + (0.15 * pow($timeElapsedFraction, 2));
        $marginAdjustment = $margin * $pointValue;

        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));
        $preGameWeight = 1 - pow($timeElapsedFraction, 0.5);
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;
        $probability = 1 / (1 + exp(-$combinedLogOdds));

        return max(0.001, min(0.999, $probability));
    }
}
