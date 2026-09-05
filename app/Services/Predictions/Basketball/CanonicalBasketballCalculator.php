<?php

namespace App\Services\Predictions\Basketball;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;

abstract class CanonicalBasketballCalculator implements SportCalculator
{
    public function calculate(
        EventInputSnapshotData $snapshot,
        CalculationReleaseData $release,
    ): PredictionOutput {
        if ($release->sport !== $this->expectedSport()
            || $release->phase !== 'pregame'
            || $snapshot->schemaVersion !== $this->expectedInputSchemaVersion()) {
            throw new PredictionLifecycleException(strtoupper($this->expectedSport()).' calculator received an incompatible release or snapshot.');
        }

        $home = (array) data_get($snapshot->inputs, 'home', []);
        $away = (array) data_get($snapshot->inputs, 'away', []);
        $homeMetrics = (array) data_get($home, 'metrics', []);
        $awayMetrics = (array) data_get($away, 'metrics', []);
        $config = $release->configuration;

        $homeElo = $this->numeric($home, 'elo');
        $awayElo = $this->numeric($away, 'elo');
        $homeCourtAdvantage = $this->numeric($config, 'elo.home_court_advantage');
        $eloDivisor = $this->positive($config, 'elo.points_per_spread_point');
        $eloHomeMargin = (($homeElo + $homeCourtAdvantage) - $awayElo) / $eloDivisor;

        $defaultEfficiency = $this->positive($config, 'total.default_efficiency');
        $averagePace = $this->positive($config, 'total.average_pace');
        $homeOffense = $this->numericOr($homeMetrics, 'offensive_efficiency', $defaultEfficiency);
        $homeDefense = $this->numericOr($homeMetrics, 'defensive_efficiency', $defaultEfficiency);
        $awayOffense = $this->numericOr($awayMetrics, 'offensive_efficiency', $defaultEfficiency);
        $awayDefense = $this->numericOr($awayMetrics, 'defensive_efficiency', $defaultEfficiency);
        $homePace = $this->numericOr($homeMetrics, 'tempo', $averagePace);
        $awayPace = $this->numericOr($awayMetrics, 'tempo', $averagePace);
        $rawPace = ($homePace + $awayPace) / 2;

        $homeSample = (int) data_get($homeMetrics, 'wins', 0) + (int) data_get($homeMetrics, 'losses', 0);
        $awaySample = (int) data_get($awayMetrics, 'wins', 0) + (int) data_get($awayMetrics, 'losses', 0);
        $minimumMetricGames = max(1, (int) $this->numeric($config, 'spread.minimum_metric_games'));
        $metricReliability = min(1.0, min($homeSample, $awaySample) / $minimumMetricGames);
        $metricWeight = $this->bounded($this->numeric($config, 'spread.metric_weight'), 0, 1) * $metricReliability;
        $homeNetRating = $this->numericOr($homeMetrics, 'net_rating', $homeOffense - $homeDefense);
        $awayNetRating = $this->numericOr($awayMetrics, 'net_rating', $awayOffense - $awayDefense);
        $metricHomeMargin = ($homeNetRating - $awayNetRating) * ($rawPace / 100);
        $blendedHomeMargin = ($eloHomeMargin * (1 - $metricWeight)) + ($metricHomeMargin * $metricWeight);

        $recentAdjustment = (
            $this->numericOr($homeMetrics, 'recent_form_rating', 0)
            - $this->numericOr($awayMetrics, 'recent_form_rating', 0)
        ) * $this->numeric($config, 'context.recent_spread_weight');
        $fatigueAdjustment = (
            $this->numericOr($awayMetrics, 'rest_travel_fatigue', 0)
            - $this->numericOr($homeMetrics, 'rest_travel_fatigue', 0)
        ) * $this->numeric($config, 'context.fatigue_spread_weight');
        $homeInjuryRating = $this->numericOr($homeMetrics, 'injury_adjusted_team_rating', $homeElo);
        $awayInjuryRating = $this->numericOr($awayMetrics, 'injury_adjusted_team_rating', $awayElo);
        $injuryRatingAdjustment = (($homeInjuryRating - $awayInjuryRating) - ($homeElo - $awayElo))
            * $this->numeric($config, 'context.injury_rating_spread_weight');

        $homeInjuryCounts = $this->injuryCounts((array) data_get($home, 'injuries', []));
        $awayInjuryCounts = $this->injuryCounts((array) data_get($away, 'injuries', []));
        $homeUnavailablePenalty = $this->spreadInjuryPenalty($homeInjuryCounts, $config);
        $awayUnavailablePenalty = $this->spreadInjuryPenalty($awayInjuryCounts, $config);
        $availabilityAdjustment = $awayUnavailablePenalty - $homeUnavailablePenalty;
        $unregressedHomeMargin = $blendedHomeMargin
            + $recentAdjustment
            + $fatigueAdjustment
            + $injuryRatingAdjustment
            + $availabilityAdjustment;
        $spreadRegression = $this->bounded($this->numeric($config, 'spread.output_regression_weight'), 0, 0.75);
        $homeMargin = round($unregressedHomeMargin * (1 - $spreadRegression), 1);

        $tempoRegression = $this->bounded($this->numeric($config, 'total.tempo_regression_weight'), 0, 1);
        $pace = ($rawPace * (1 - $tempoRegression)) + ($averagePace * $tempoRegression);
        $homePointsPerHundred = ($homeOffense + $awayDefense) / 2;
        $awayPointsPerHundred = ($awayOffense + $homeDefense) / 2;
        $rawTotal = ($homePointsPerHundred + $awayPointsPerHundred) * ($pace / 100);
        $recentTotalAdjustment = (
            $this->numericOr($homeMetrics, 'recent_form_rating', 0)
            + $this->numericOr($awayMetrics, 'recent_form_rating', 0)
        ) * $this->numeric($config, 'context.recent_total_weight');
        $fatigueTotalAdjustment = -(
            $this->numericOr($homeMetrics, 'rest_travel_fatigue', 0)
            + $this->numericOr($awayMetrics, 'rest_travel_fatigue', 0)
        ) * $this->numeric($config, 'context.fatigue_total_weight');
        $injuryRatingLoss = max(0, $homeElo - $homeInjuryRating) + max(0, $awayElo - $awayInjuryRating);
        $injuryRatingTotalAdjustment = -$injuryRatingLoss
            * $this->numeric($config, 'context.injury_rating_total_weight');
        $availabilityTotalAdjustment = -$this->totalInjuryPenalty($homeInjuryCounts, $awayInjuryCounts, $config);
        $adjustedTotal = $rawTotal
            + $recentTotalAdjustment
            + $fatigueTotalAdjustment
            + $injuryRatingTotalAdjustment
            + $availabilityTotalAdjustment;
        $averageTotal = $this->positive($config, 'total.average_total');
        $totalRegression = $this->bounded($this->numeric($config, 'total.output_regression_weight'), 0, 0.75);
        $projectedTotal = round(($adjustedTotal * (1 - $totalRegression)) + ($averageTotal * $totalRegression), 1);

        $probabilityCoefficient = $this->positive($config, 'spread.probability_coefficient');
        $homeProbability = round(1 / (1 + exp(-$homeMargin / $probabilityCoefficient)), 6);
        $awayProbability = round(1 - $homeProbability, 6);
        $confidence = round(max($homeProbability, $awayProbability) * 100, 2);
        $reasonCodes = $this->reasonCodes(
            $eloHomeMargin,
            $metricHomeMargin,
            $recentAdjustment,
            $fatigueAdjustment,
            $availabilityAdjustment,
        );

        return new PredictionOutput(
            markets: [
                new PredictionMarketOutput('moneyline', 'home', probability: $homeProbability, confidenceScore: $confidence),
                new PredictionMarketOutput('moneyline', 'away', probability: $awayProbability, confidenceScore: $confidence),
                // Canonical spread lines use sportsbook sign: a favored home team has a negative line.
                new PredictionMarketOutput('spread', 'home', projectedLine: -$homeMargin, confidenceScore: $confidence),
                new PredictionMarketOutput('total', 'combined', projectedLine: $projectedTotal, confidenceScore: $confidence),
            ],
            metadata: [
                'home_margin' => $homeMargin,
                'reason_codes' => $reasonCodes,
                'market_conventions' => [
                    'spread' => 'sportsbook_home_line',
                    'total' => 'combined_points',
                    'moneyline_probability' => 'decimal_zero_to_one',
                ],
            ],
            diagnostics: [
                'elo_home_margin' => round($eloHomeMargin, 4),
                'metric_home_margin' => round($metricHomeMargin, 4),
                'metric_reliability' => round($metricReliability, 4),
                'metric_weight' => round($metricWeight, 4),
                'recent_adjustment' => round($recentAdjustment, 4),
                'fatigue_adjustment' => round($fatigueAdjustment, 4),
                'injury_rating_adjustment' => round($injuryRatingAdjustment, 4),
                'availability_adjustment' => round($availabilityAdjustment, 4),
                'raw_total' => round($rawTotal, 4),
                'projected_total' => $projectedTotal,
                'home_injuries' => $homeInjuryCounts,
                'away_injuries' => $awayInjuryCounts,
            ],
            generatedAt: $snapshot->capturedAt,
        );
    }

    /** @param array<int, mixed> $injuries @return array{out:int,questionable:int} */
    private function injuryCounts(array $injuries): array
    {
        $counts = ['out' => 0, 'questionable' => 0];

        foreach ($injuries as $injury) {
            $status = strtolower((string) data_get($injury, 'status', ''));

            if (str_contains($status, 'out') || str_contains($status, 'doubtful')) {
                $counts['out']++;
            } elseif (str_contains($status, 'questionable') || str_contains($status, 'day-to-day')) {
                $counts['questionable']++;
            }
        }

        return $counts;
    }

    /** @param array{out:int,questionable:int} $counts @param array<string,mixed> $config */
    private function spreadInjuryPenalty(array $counts, array $config): float
    {
        return ($counts['out'] * $this->numeric($config, 'injuries.out_spread_penalty'))
            + ($counts['questionable'] * $this->numeric($config, 'injuries.questionable_spread_penalty'));
    }

    /**
     * @param  array{out:int,questionable:int}  $home
     * @param  array{out:int,questionable:int}  $away
     * @param  array<string,mixed>  $config
     */
    private function totalInjuryPenalty(array $home, array $away, array $config): float
    {
        return (($home['out'] + $away['out']) * $this->numeric($config, 'injuries.out_total_penalty'))
            + (($home['questionable'] + $away['questionable']) * $this->numeric($config, 'injuries.questionable_total_penalty'));
    }

    /** @return list<string> */
    private function reasonCodes(
        float $eloMargin,
        float $metricMargin,
        float $recentAdjustment,
        float $fatigueAdjustment,
        float $availabilityAdjustment,
    ): array {
        $codes = [];
        $codes[] = $eloMargin >= 0 ? 'HOME_ELO_EDGE' : 'AWAY_ELO_EDGE';
        $codes[] = $metricMargin >= 0 ? 'HOME_EFFICIENCY_EDGE' : 'AWAY_EFFICIENCY_EDGE';

        foreach ([
            'RECENT_FORM' => $recentAdjustment,
            'REST_FATIGUE' => $fatigueAdjustment,
            'PLAYER_AVAILABILITY' => $availabilityAdjustment,
        ] as $label => $adjustment) {
            if (abs($adjustment) >= 0.25) {
                $codes[] = ($adjustment > 0 ? 'HOME_' : 'AWAY_').$label.'_EDGE';
            }
        }

        return $codes;
    }

    /** @param array<string,mixed> $values */
    private function numeric(array $values, string $key): float
    {
        $value = data_get($values, $key);

        if (! is_numeric($value)) {
            throw new PredictionLifecycleException(strtoupper($this->expectedSport())." calculation input {$key} must be numeric.");
        }

        return (float) $value;
    }

    /** @param array<string,mixed> $values */
    private function numericOr(array $values, string $key, float $fallback): float
    {
        $value = data_get($values, $key);

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /** @param array<string,mixed> $values */
    private function positive(array $values, string $key): float
    {
        $value = $this->numeric($values, $key);

        if ($value <= 0) {
            throw new PredictionLifecycleException(strtoupper($this->expectedSport())." calculation input {$key} must be greater than zero.");
        }

        return $value;
    }

    private function bounded(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }

    abstract protected function expectedSport(): string;

    abstract protected function expectedInputSchemaVersion(): string;
}
