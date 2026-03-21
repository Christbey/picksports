<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractAdvancedBasketballUpdateLivePrediction;
use App\Models\CBB\TeamPossessionMetric;
use Carbon\Carbon;

class UpdateLivePrediction extends AbstractAdvancedBasketballUpdateLivePrediction
{
    protected const TOTAL_GAME_SECONDS = 2400;

    protected const REGULATION_PERIODS = 2;

    protected const SECONDS_PER_PERIOD = 1200;

    protected const DEFAULT_PRE_GAME_TOTAL = 140;

    protected const UPPER_BOUND_BASE = 220;

    private ?array $liveContext = null;

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
        $this->liveContext = $this->buildLiveContext(
            $game,
            $secondsRemaining,
            $effectiveGameLength,
            (float) ($prediction->predicted_total ?? static::DEFAULT_PRE_GAME_TOTAL)
        );

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
            $period,
            $margin,
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
        $preGameWeight = pow($remainingTimeRatio, 0.65);
        $stateWeight = 0.15 + (0.75 * $timeElapsedFraction);
        $projectedFinalMargin = $this->projectedFinalMargin($margin, $secondsRemaining, $timeElapsedFraction, 0.0);
        $marginScale = 4.2 + (10.5 * $remainingTimeRatio);
        $marginAdjustment = ($projectedFinalMargin / $marginScale) * $stateWeight;
        $efficiencyMargin = (float) ($this->liveContext['expected_remaining_margin'] ?? 0.0);
        $efficiencyAdjustment = ($efficiencyMargin / 10.0) * min(0.50, $timeElapsedFraction);
        $combinedLogOdds = ($preGameLogOdds * $preGameWeight) + $marginAdjustment + $efficiencyAdjustment;

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
        $stateWeight = 0.20 + (0.70 * $timeElapsedFraction);
        $scoreStateDampener = max(0.30, 1 - (abs($currentMargin) / 24));
        $pregameCarryWeight = (0.60 + (0.25 * $remainingFraction)) * $scoreStateDampener;
        $remainingPreGameContribution = $preGameSpread * $remainingFraction * $pregameCarryWeight;
        $efficiencyMargin = (float) ($this->liveContext['expected_remaining_margin'] ?? 0.0) * $stateWeight * 0.75;

        return $currentMargin + $remainingPreGameContribution + $efficiencyMargin;
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
        $paceProjectedFullGame = $actualRate * $effectiveGameLength;

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

        $metricsRemainingPoints = (float) ($this->liveContext['expected_remaining_total_points'] ?? 0.0);
        $metricsProjectedFullGame = $currentTotal + ($metricsRemainingPoints * $scoreStateMultiplier);
        $metricsWeight = (float) config('cbb.prediction.live_possession.live_total_metrics_weight', 0.65) * min(0.60, $timeElapsedFraction);
        $paceWeight = min(0.55, pow($timeElapsedFraction, 1.35));
        $pregameWeight = max(0.15, 1 - $paceWeight - $metricsWeight);
        $weightTotal = $pregameWeight + $paceWeight + $metricsWeight;
        $pregameWeight /= $weightTotal;
        $paceWeight /= $weightTotal;
        $metricsWeight /= $weightTotal;

        $liveTotal = ($preGameTotal * $pregameWeight)
            + ($paceProjectedFullGame * $paceWeight)
            + ($metricsProjectedFullGame * $metricsWeight);

        $upperBound = static::UPPER_BOUND_BASE;
        if ($period > static::REGULATION_PERIODS) {
            $otPeriods = $period - static::REGULATION_PERIODS;
            $upperBound += $otPeriods * 25;
        }

        $lowerBound = max((float) $currentTotal, $preGameTotal - 18);

        return max($lowerBound, min($upperBound, $liveTotal));
    }

    private function buildLiveContext(object $game, int $secondsRemaining, int $effectiveGameLength, float $preGameTotal): array
    {
        $config = (array) config('cbb.prediction.live_possession', []);
        if (! ($config['enabled'] ?? true)) {
            return [];
        }

        $homeMetric = $this->latestPossessionMetric((int) $game->home_team_id, (int) $game->season);
        $awayMetric = $this->latestPossessionMetric((int) $game->away_team_id, (int) $game->season);

        if (! $homeMetric || ! $awayMetric) {
            return [];
        }

        $minimumSample = (int) ($config['minimum_sample_possessions'] ?? 40);
        if (($homeMetric->rolling_offensive_possessions ?? 0) < $minimumSample || ($awayMetric->rolling_offensive_possessions ?? 0) < $minimumSample) {
            return [];
        }

        $baseTempo = (
            (float) ($homeMetric->possessions_per_game ?? 0)
            + (float) ($awayMetric->possessions_per_game ?? 0)
        ) / 2;
        $tempoBlendWeight = (float) ($config['tempo_blend_weight'] ?? 0.55);
        $pregameTempo = max(55.0, $preGameTotal / 2.1);
        $blendedTempo = ($baseTempo * $tempoBlendWeight) + ($pregameTempo * (1 - $tempoBlendWeight));
        $remainingPossessions = max(0.0, $blendedTempo * ($secondsRemaining / max(1, $effectiveGameLength)));

        $lateGameWeight = $secondsRemaining <= 300 ? (float) ($config['late_game_ppp_weight'] ?? 0.60) : 0.0;
        $homeOffPpp = $this->blendPpp(
            (float) ($homeMetric->rolling_offensive_points_per_possession ?? $homeMetric->offensive_points_per_possession),
            (float) ($homeMetric->late_game_offensive_points_per_possession ?? 0),
            $lateGameWeight
        );
        $awayOffPpp = $this->blendPpp(
            (float) ($awayMetric->rolling_offensive_points_per_possession ?? $awayMetric->offensive_points_per_possession),
            (float) ($awayMetric->late_game_offensive_points_per_possession ?? 0),
            $lateGameWeight
        );
        $homeDefPppAllowed = $this->blendPpp(
            (float) ($homeMetric->rolling_defensive_points_per_possession_allowed ?? $homeMetric->defensive_points_per_possession_allowed),
            (float) ($homeMetric->late_game_defensive_points_per_possession_allowed ?? 0),
            $lateGameWeight
        );
        $awayDefPppAllowed = $this->blendPpp(
            (float) ($awayMetric->rolling_defensive_points_per_possession_allowed ?? $awayMetric->defensive_points_per_possession_allowed),
            (float) ($awayMetric->late_game_defensive_points_per_possession_allowed ?? 0),
            $lateGameWeight
        );

        $expectedHomePpp = ($homeOffPpp + $awayDefPppAllowed) / 2;
        $expectedAwayPpp = ($awayOffPpp + $homeDefPppAllowed) / 2;

        $efficiencyWeight = (float) ($config['efficiency_margin_weight'] ?? 0.90);
        $pregameMarginWeight = (float) ($config['pregame_margin_weight'] ?? 0.40);
        $expectedRemainingMargin = (($expectedHomePpp - $expectedAwayPpp) * $remainingPossessions * $efficiencyWeight)
            + (((float) ($game->prediction->predicted_spread ?? 0)) * ($secondsRemaining / max(1, static::TOTAL_GAME_SECONDS)) * $pregameMarginWeight);

        return [
            'remaining_possessions' => round($remainingPossessions, 3),
            'expected_home_ppp' => round($expectedHomePpp, 3),
            'expected_away_ppp' => round($expectedAwayPpp, 3),
            'expected_remaining_margin' => round($expectedRemainingMargin, 3),
            'expected_remaining_total_points' => round(($expectedHomePpp + $expectedAwayPpp) * $remainingPossessions, 3),
        ];
    }

    private function latestPossessionMetric(int $teamId, int $season): ?TeamPossessionMetric
    {
        return TeamPossessionMetric::query()
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->orderByDesc('as_of_date')
            ->first();
    }

    private function blendPpp(float $base, float $late, float $lateWeight): float
    {
        if ($late <= 0 || $lateWeight <= 0) {
            return $base;
        }

        return ($base * (1 - $lateWeight)) + ($late * $lateWeight);
    }

    private function projectedFinalMargin(int $currentMargin, int $secondsRemaining, float $timeElapsedFraction, float $preGameSpread): float
    {
        $remainingFraction = max(0.0, min(1.0, $secondsRemaining / static::TOTAL_GAME_SECONDS));
        $expectedRemainingMargin = (float) ($this->liveContext['expected_remaining_margin'] ?? 0.0);
        $stateProjection = $currentMargin + $expectedRemainingMargin;
        $stateWeight = 0.18 + (0.82 * pow($timeElapsedFraction, 1.1));
        $earlyMarginCompression = 1.0;

        if ($secondsRemaining > 180) {
            $earlyMarginCompression -= min(0.30, (abs($currentMargin) / 30) * (1 - $timeElapsedFraction));
        }

        $stateProjection *= $earlyMarginCompression;
        $pregameAnchor = $preGameSpread * (0.55 + (0.25 * $remainingFraction));

        return ($pregameAnchor * (1 - $stateWeight)) + ($stateProjection * $stateWeight);
    }
}
