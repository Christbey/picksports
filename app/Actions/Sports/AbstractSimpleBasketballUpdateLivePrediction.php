<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\ManagesTimedLivePredictions;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Database\Eloquent\Model;

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
        if (! $game instanceof Model) {
            return null;
        }
        $store = app(LivePredictionStore::class);
        if (! $this->isGameInProgress($game)) {
            $store->clear($game, static::class);

            return null;
        }
        $prediction = $store->baseline($game);
        if ($prediction === null) {
            $store->record($game, null, null, 'missing_pregame', static::class);

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
            $prediction->predicted_total ?? static::DEFAULT_PRE_GAME_TOTAL
        );

        $values = [
            'live_predicted_spread' => round($livePredictedSpread, 1),
            'live_win_probability' => round($liveWinProbability, 3),
            'live_predicted_total' => round($livePredictedTotal, 1),
            'live_seconds_remaining' => $secondsRemaining,
        ];
        $store->record($game, $prediction, $values, 'live', static::class);

        return $values;
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
        $elapsedInCurrentOt = static::MAX_OT_SECONDS - min($clockSeconds, static::MAX_OT_SECONDS);

        return static::TOTAL_GAME_SECONDS + ($completedOtPeriods * static::MAX_OT_SECONDS) + $elapsedInCurrentOt;
    }

    protected function calculateEffectiveGameLength(int $period): int
    {
        if ($period <= static::REGULATION_PERIODS) {
            return static::TOTAL_GAME_SECONDS;
        }

        $otPeriods = $period - static::REGULATION_PERIODS;

        return static::TOTAL_GAME_SECONDS + ($otPeriods * static::MAX_OT_SECONDS);
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

        return max($currentTotal, min(static::UPPER_BOUND, $liveTotal));
    }

    public function clearLivePrediction(object $prediction): void
    {
        $this->clearTimedLivePrediction($prediction);
    }
}
