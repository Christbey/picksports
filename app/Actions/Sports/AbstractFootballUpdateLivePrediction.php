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
            return null;
        }

        $prediction = $game->prediction;

        if (! $prediction) {
            return null;
        }

        $secondsRemaining = $this->calculateSecondsRemaining($game->period, $game->game_clock);
        $secondsElapsed = self::TOTAL_GAME_SECONDS - $secondsRemaining;
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

    protected function calculateLiveWinProbability(int $margin, int $secondsRemaining, float $preGameProbability): float
    {
        return $this->footballLiveWinProbability(
            $margin,
            $secondsRemaining,
            $preGameProbability,
            self::TOTAL_GAME_SECONDS
        );
    }

    protected function calculateLiveSpread(int $currentMargin, int $secondsRemaining, float $preGameSpread): float
    {
        if ($secondsRemaining <= 0) {
            return (float) $currentMargin;
        }

        $timeElapsedFraction = 1 - ($secondsRemaining / self::TOTAL_GAME_SECONDS);
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

        $timeElapsedFraction = $secondsElapsed / self::TOTAL_GAME_SECONDS;
        $currentPace = $currentTotal / $secondsElapsed;
        $pacePredictedTotal = $currentPace * self::TOTAL_GAME_SECONDS;
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
