<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractAdvancedBasketballUpdateLivePrediction;
use Carbon\Carbon;

class UpdateLivePrediction extends AbstractAdvancedBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2880;

    protected const REGULATION_PERIODS = 4;

    protected const SECONDS_PER_PERIOD = 720;

    protected const DEFAULT_PRE_GAME_TOTAL = 220;

    protected const UPPER_BOUND_BASE = 300;

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

        $state = $this->previewState(
            $game,
            $prediction,
            (int) $game->period,
            $game->game_clock,
            (int) ($game->home_score ?? 0),
            (int) ($game->away_score ?? 0)
        );

        if ($state === null) {
            return null;
        }

        $prediction->update([
            'live_predicted_spread' => $state['live_predicted_spread'],
            'live_win_probability' => $state['live_win_probability'],
            'live_predicted_total' => $state['live_predicted_total'],
            'live_seconds_remaining' => $state['live_seconds_remaining'],
            'live_updated_at' => Carbon::now(),
        ]);

        return $state;
    }

    /**
     * @return array{live_predicted_spread: float, live_win_probability: float, live_predicted_total: float, live_seconds_remaining: int}|null
     */
    public function previewState(object $game, object $prediction, int $period, ?string $gameClock, int $homeScore, int $awayScore): ?array
    {
        $secondsRemaining = $this->calculateSecondsRemaining($period, $gameClock);
        $actualSecondsElapsed = $this->calculateActualSecondsElapsed($period, $gameClock);
        $effectiveGameLength = $this->calculateEffectiveGameLength($period);
        $timeElapsedFraction = min(1.0, $actualSecondsElapsed / $effectiveGameLength);
        $margin = $homeScore - $awayScore;
        $totalPoints = $homeScore + $awayScore;

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
            $period,
            $prediction->predicted_total ?? static::DEFAULT_PRE_GAME_TOTAL
        );

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
        $preGameWeight = pow($remainingTimeRatio, 0.62);
        $stateWeight = 0.18 + (0.84 * $timeElapsedFraction);
        $projectedFinalMargin = $this->projectedFinalMargin($margin, $secondsRemaining, $timeElapsedFraction, 0.0);
        $marginScale = 4.4 + (8.2 * $remainingTimeRatio);
        $marginAdjustment = ($projectedFinalMargin / $marginScale) * $stateWeight;
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment;

        $probability = 1 / (1 + exp(-$combinedLogOdds));

        if ($secondsRemaining <= 120 && abs($margin) >= 3) {
            $lateLeadBoost = min(0.16, (abs($margin) / 14) * 0.16);
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
        $scoreStateDampener = max(0.28, 1 - (abs($currentMargin) / 26));
        $pregameCarryWeight = (0.62 + (0.22 * $remainingFraction)) * $scoreStateDampener;
        $remainingPreGameContribution = $preGameSpread * $remainingFraction * $pregameCarryWeight;

        return $currentMargin + $remainingPreGameContribution;
    }

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
        $actualRate = $currentTotal / max(1, $actualSecondsElapsed);
        $paceProjectedFullGame = $actualRate * $effectiveGameLength;
        $pointsPerMinute = $actualRate * 60;
        $config = (array) config('nba.prediction.live_model', []);

        $scoreStateMultiplier = 1.0;
        if ($secondsRemaining <= 180 && $pointsPerMinute <= 4.2) {
            $scoreStateMultiplier -= (float) ($config['late_blowout_total_penalty'] ?? 0.10);
        }
        if ($secondsRemaining <= 90 && $pointsPerMinute >= 4.6) {
            $scoreStateMultiplier += (float) ($config['tight_game_total_boost'] ?? 0.10);
        }
        if ($secondsRemaining <= 45 && $pointsPerMinute >= 4.8) {
            $scoreStateMultiplier += (float) ($config['very_tight_game_total_boost'] ?? 0.05);
        }

        $paceProjectedFullGame *= $scoreStateMultiplier;
        $paceWeight = min(0.60, pow($timeElapsedFraction, 1.25));
        $pregameWeight = max(0.18, 1 - $paceWeight);
        $weightTotal = $pregameWeight + $paceWeight;
        $pregameWeight /= $weightTotal;
        $paceWeight /= $weightTotal;

        $liveTotal = ($preGameTotal * $pregameWeight) + ($paceProjectedFullGame * $paceWeight);

        $upperBound = static::UPPER_BOUND_BASE;
        if ($period > static::REGULATION_PERIODS) {
            $otPeriods = $period - static::REGULATION_PERIODS;
            $upperBound += $otPeriods * 25;
        }

        $lowerBound = max((float) $currentTotal, $preGameTotal - (float) ($config['pregame_total_floor_buffer'] ?? 20.0));

        return max($lowerBound, min($upperBound, $liveTotal));
    }

    private function projectedFinalMargin(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        $remainingFraction = max(0.0, min(1.0, $secondsRemaining / static::TOTAL_GAME_SECONDS));
        $stateProjection = $timeElapsedFraction > 0
            ? $currentMargin / max(0.35, $timeElapsedFraction)
            : (float) $currentMargin;
        $stateWeight = 0.16 + (0.80 * pow($timeElapsedFraction, 1.05));
        $earlyMarginCompression = 1.0;

        if ($secondsRemaining > 180) {
            $earlyMarginCompression -= min(0.28, (abs($currentMargin) / 28) * (1 - $timeElapsedFraction));
        }

        $stateProjection *= $earlyMarginCompression;
        $pregameAnchor = $preGameSpread * (0.58 + (0.22 * $remainingFraction));

        return ($pregameAnchor * (1 - $stateWeight)) + ($stateProjection * $stateWeight);
    }
}
