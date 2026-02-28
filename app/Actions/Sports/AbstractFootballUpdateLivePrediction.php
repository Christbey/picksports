<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\CalculatesFootballLiveProbability;
use App\Actions\Sports\Concerns\ManagesTimedLivePredictions;
use Carbon\Carbon;

abstract class AbstractFootballUpdateLivePrediction
{
    use CalculatesFootballLiveProbability;
    use ManagesTimedLivePredictions;

    protected const TOTAL_GAME_SECONDS = 3600; // 4 quarters * 15 minutes * 60 seconds

    protected const SECONDS_PER_QUARTER = 900; // 15 minutes

    protected const MAX_OVERTIME_SECONDS = 600; // treat as 10 minutes max

    protected const LIVE_TOTAL_UPPER_BOUND = 100;

    protected const DEFAULT_PRE_GAME_TOTAL = 45;

    /**
     * Update live prediction data for an in-progress game.
     *
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

        $livePredictedTotal = $this->calculateLiveTotal(
            $totalPoints,
            $actualSecondsElapsed,
            $secondsRemaining,
            $effectiveGameLength,
            $prediction->predicted_total ?? self::DEFAULT_PRE_GAME_TOTAL
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

    protected function isGameInProgress(object $game): bool
    {
        return $this->footballIsGameInProgress($game);
    }

    protected function calculateSecondsRemaining(int $period, ?string $gameClock): int
    {
        return $this->footballSecondsRemaining(
            $period,
            $gameClock,
            self::TOTAL_GAME_SECONDS,
            self::SECONDS_PER_QUARTER,
            self::MAX_OVERTIME_SECONDS
        );
    }

    protected function calculateActualSecondsElapsed(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return 0;
        }

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= 4) {
            $completedQuarters = $period - 1;
            $elapsedInCurrentQuarter = self::SECONDS_PER_QUARTER - $clockSeconds;

            return ($completedQuarters * self::SECONDS_PER_QUARTER) + $elapsedInCurrentQuarter;
        }

        $completedOtPeriods = $period - 5;
        $elapsedInCurrentOt = self::MAX_OVERTIME_SECONDS - min($clockSeconds, self::MAX_OVERTIME_SECONDS);

        return self::TOTAL_GAME_SECONDS + ($completedOtPeriods * self::MAX_OVERTIME_SECONDS) + $elapsedInCurrentOt;
    }

    protected function calculateEffectiveGameLength(int $period): int
    {
        if ($period <= 4) {
            return self::TOTAL_GAME_SECONDS;
        }

        $otPeriods = $period - 4;

        return self::TOTAL_GAME_SECONDS + ($otPeriods * self::MAX_OVERTIME_SECONDS);
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

        $remainingPreGameContribution = $preGameSpread * (1 - $timeElapsedFraction);
        $currentPaceMargin = $timeElapsedFraction > 0
            ? ($currentMargin / $timeElapsedFraction) * (1 - $timeElapsedFraction)
            : 0;

        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $timeElapsedFraction))
            + ($currentPaceMargin * $timeElapsedFraction * 0.3);

        $regressionWeight = pow($timeElapsedFraction, 2);

        return ($liveSpread * (1 - $regressionWeight)) + ($currentMargin * $regressionWeight);
    }

    protected function calculateLiveTotal(
        int $currentTotal,
        int $actualSecondsElapsed,
        int $secondsRemaining,
        int $effectiveGameLength,
        float $preGameTotal
    ): float {
        if ($secondsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($actualSecondsElapsed <= 0) {
            return $preGameTotal;
        }

        $timeElapsedFraction = $actualSecondsElapsed / $effectiveGameLength;
        $currentPace = $currentTotal / $actualSecondsElapsed;
        $pacePredictedTotal = $currentPace * $effectiveGameLength;
        $remainingPreGamePoints = $preGameTotal * (1 - $timeElapsedFraction);
        $paceWeight = pow($timeElapsedFraction, 0.7);

        $projectedRemaining = ($pacePredictedTotal - $currentTotal) * $paceWeight
            + $remainingPreGamePoints * (1 - $paceWeight);

        $liveTotal = $currentTotal + max(0, $projectedRemaining);

        return max($currentTotal, min(self::LIVE_TOTAL_UPPER_BOUND, $liveTotal));
    }

    public function clearLivePrediction(object $prediction): void
    {
        $this->clearTimedLivePrediction($prediction);
    }
}
