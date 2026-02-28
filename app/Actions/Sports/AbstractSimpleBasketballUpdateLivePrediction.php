<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\ManagesTimedLivePredictions;
use Carbon\Carbon;

abstract class AbstractSimpleBasketballUpdateLivePrediction
{
    use ManagesTimedLivePredictions;

    protected const TOTAL_GAME_SECONDS = 0;

    protected const REGULATION_PERIODS = 0;

    protected const SECONDS_PER_PERIOD = 0;

    protected const MAX_OT_SECONDS = 300;

    protected const DEFAULT_PRE_GAME_TOTAL = 0;

    protected const UPPER_BOUND = 0;

    /**
     * @var array<int, string>
     */
    protected const IN_PROGRESS_STATUSES = [
        'STATUS_IN_PROGRESS',
        'STATUS_HALFTIME',
        'STATUS_END_PERIOD',
    ];

    /**
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_seconds_remaining: int}|null
     */
    public function execute(object $game): ?array
    {
        if (! $this->isGameInProgress($game)) {
            return null;
        }

        $prediction = $game->prediction;

        if (! $prediction) {
            return null;
        }

        $secondsRemaining = $this->calculateSecondsRemaining($game->period, $game->game_clock);
        $secondsElapsed = static::TOTAL_GAME_SECONDS - $secondsRemaining;
        $margin = ($game->home_score ?? 0) - ($game->away_score ?? 0);
        $totalPoints = ($game->home_score ?? 0) + ($game->away_score ?? 0);

        $liveWinProbability = $this->calculateLiveWinProbability(
            $margin,
            $secondsRemaining,
            $prediction->win_probability ?? 0.5
        );

        $livePredictedSpread = $this->calculateLiveSpread(
            $margin,
            $secondsRemaining,
            $prediction->predicted_spread ?? 0
        );

        $livePredictedTotal = $this->calculateLiveTotal(
            $totalPoints,
            $secondsElapsed,
            $secondsRemaining,
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

        return min($clockSeconds, static::MAX_OT_SECONDS);
    }

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

        $timeElapsedFraction = 1 - ($secondsRemaining / static::TOTAL_GAME_SECONDS);
        $pointValue = 0.02 + (0.15 * pow($timeElapsedFraction, 2));
        $marginAdjustment = $margin * $pointValue;
        $preGameProbability = max(0.01, min(0.99, $preGameProbability));
        $preGameLogOdds = log($preGameProbability / (1 - $preGameProbability));
        $preGameWeight = 1 - pow($timeElapsedFraction, 0.5);
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;
        $probability = 1 / (1 + exp(-$combinedLogOdds));

        return max(0.001, min(0.999, $probability));
    }

    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $timeElapsedFraction = 1 - ($secondsRemaining / static::TOTAL_GAME_SECONDS);
        $remainingPreGameContribution = $preGameSpread * (1 - $timeElapsedFraction);
        $currentPaceMargin = $timeElapsedFraction > 0
            ? ($currentMargin / $timeElapsedFraction) * (1 - $timeElapsedFraction)
            : 0;

        $liveSpread = $currentMargin + ($remainingPreGameContribution * (1 - $timeElapsedFraction))
            + ($currentPaceMargin * $timeElapsedFraction * 0.3);

        $regressionWeight = pow($timeElapsedFraction, 2);

        return ($liveSpread * (1 - $regressionWeight)) + ($currentMargin * $regressionWeight);
    }

    protected function calculateLiveTotal(int $currentTotal, int $secondsElapsed, int $secondsRemaining, float $preGameTotal): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentTotal;
        }

        if ($secondsElapsed <= 0) {
            return $preGameTotal;
        }

        $timeElapsedFraction = $secondsElapsed / static::TOTAL_GAME_SECONDS;
        $currentPace = $currentTotal / $secondsElapsed;
        $pacePredictedTotal = $currentPace * static::TOTAL_GAME_SECONDS;
        $remainingPreGamePoints = $preGameTotal * (1 - $timeElapsedFraction);
        $paceWeight = pow($timeElapsedFraction, 0.7);

        $projectedRemaining = ($pacePredictedTotal - $currentTotal) * $paceWeight
            + $remainingPreGamePoints * (1 - $paceWeight);

        $liveTotal = $currentTotal + max(0, $projectedRemaining);

        return max($currentTotal, min(static::UPPER_BOUND, $liveTotal));
    }

    public function clearLivePrediction(object $prediction): void
    {
        $this->clearTimedLivePrediction($prediction);
    }
}
