<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\ManagesTimedLivePredictions;
use Carbon\Carbon;

abstract class AbstractAdvancedBasketballUpdateLivePrediction
{
    use ManagesTimedLivePredictions;

    protected const TOTAL_GAME_SECONDS = 0;

    protected const REGULATION_PERIODS = 0;

    protected const SECONDS_PER_PERIOD = 0;

    protected const SECONDS_PER_OT = 300;

    protected const DEFAULT_PRE_GAME_TOTAL = 0;

    protected const UPPER_BOUND_BASE = 0;

    /**
     * @var array<int, string>
     */
    protected const IN_PROGRESS_STATUSES = [
        'STATUS_IN_PROGRESS',
        'STATUS_HALFTIME',
        'STATUS_END_PERIOD',
        'STATUS_SUSPENDED',
    ];

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
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalPoints = ($game->home_score ?? 0) + ($game->away_score ?? 0);
        $effectiveGameLength = $this->calculateEffectiveGameLength($game->period);
        $timeElapsedFraction = min(1.0, $actualSecondsElapsed / $effectiveGameLength);

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
            $game->period,
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

    protected function isGameInProgress(object $game): bool
    {
        return in_array($game->status, static::IN_PROGRESS_STATUSES, true) && $game->period >= 1;
    }

    protected function calculateSecondsRemaining(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return static::TOTAL_GAME_SECONDS;
        }

        $periodsRemaining = max(0, static::REGULATION_PERIODS - $period);
        $secondsFromPeriods = $periodsRemaining * static::SECONDS_PER_PERIOD;
        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= static::REGULATION_PERIODS) {
            return $secondsFromPeriods + $clockSeconds;
        }

        return min($clockSeconds, static::SECONDS_PER_OT);
    }

    protected function calculateActualSecondsElapsed(int $period, ?string $gameClock): int
    {
        if ($period < 1) {
            return 0;
        }

        $clockSeconds = $this->parseGameClock($gameClock);

        if ($period <= static::REGULATION_PERIODS) {
            $completedPeriods = $period - 1;
            $elapsedInCurrentPeriod = static::SECONDS_PER_PERIOD - $clockSeconds;

            return ($completedPeriods * static::SECONDS_PER_PERIOD) + $elapsedInCurrentPeriod;
        }

        $completedOtPeriods = $period - (static::REGULATION_PERIODS + 1);
        $elapsedInCurrentOt = static::SECONDS_PER_OT - min($clockSeconds, static::SECONDS_PER_OT);

        return static::TOTAL_GAME_SECONDS + ($completedOtPeriods * static::SECONDS_PER_OT) + $elapsedInCurrentOt;
    }

    protected function calculateEffectiveGameLength(int $period): int
    {
        if ($period <= static::REGULATION_PERIODS) {
            return static::TOTAL_GAME_SECONDS;
        }

        $otPeriods = $period - static::REGULATION_PERIODS;

        return static::TOTAL_GAME_SECONDS + ($otPeriods * static::SECONDS_PER_OT);
    }

    abstract protected function calculateLiveWinProbability(int $margin, int $secondsRemaining, float $timeElapsedFraction, float $preGameProbability): float;

    abstract protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float;

    protected function calculateLiveTotal(
        int $currentTotal,
        int $actualSecondsElapsed,
        int $secondsRemaining,
        int $effectiveGameLength,
        int $period,
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

        $upperBound = static::UPPER_BOUND_BASE;
        if ($period > static::REGULATION_PERIODS) {
            $otPeriods = $period - static::REGULATION_PERIODS;
            $upperBound += $otPeriods * 25;
        }

        return max($currentTotal, min($upperBound, $liveTotal));
    }

    public function clearLivePrediction(object $prediction): void
    {
        $this->clearTimedLivePrediction($prediction);
    }
}
