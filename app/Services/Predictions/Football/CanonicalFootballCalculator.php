<?php

namespace App\Services\Predictions\Football;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;

abstract class CanonicalFootballCalculator implements SportCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        if ($release->sport !== $this->expectedSport()
            || $release->phase !== 'pregame'
            || $snapshot->schemaVersion !== $this->expectedInputSchemaVersion()) {
            throw new PredictionLifecycleException(strtoupper($this->expectedSport()).' calculator received incompatible lifecycle data.');
        }

        $home = (array) data_get($snapshot->inputs, 'home', []);
        $away = (array) data_get($snapshot->inputs, 'away', []);
        $homeMetrics = (array) data_get($home, 'metrics', []);
        $awayMetrics = (array) data_get($away, 'metrics', []);
        $config = $release->configuration;
        $neutralSite = (bool) data_get($snapshot->inputs, 'event.neutral_site', false);
        $homeElo = $this->numeric($home, 'elo');
        $awayElo = $this->numeric($away, 'elo');
        $homeFieldAdvantage = $neutralSite ? 0.0 : $this->numeric($config, 'elo.home_field_advantage');
        $eloMargin = (($homeElo + $homeFieldAdvantage) - $awayElo)
            / $this->positive($config, 'elo.points_per_spread_point');

        $defaultPoints = $this->positive($config, 'total.default_team_points');
        $homeScoring = $this->numericOr($homeMetrics, 'points_per_game', $defaultPoints);
        $homeAllowed = $this->numericOr($homeMetrics, 'points_allowed_per_game', $defaultPoints);
        $awayScoring = $this->numericOr($awayMetrics, 'points_per_game', $defaultPoints);
        $awayAllowed = $this->numericOr($awayMetrics, 'points_allowed_per_game', $defaultPoints);
        $homeExpected = ($homeScoring + $awayAllowed) / 2;
        $awayExpected = ($awayScoring + $homeAllowed) / 2;
        $metricMargin = $homeExpected - $awayExpected;

        $homeSample = (int) data_get($homeMetrics, 'wins', 0) + (int) data_get($homeMetrics, 'losses', 0);
        $awaySample = (int) data_get($awayMetrics, 'wins', 0) + (int) data_get($awayMetrics, 'losses', 0);
        $minimumGames = max(1, (int) $this->numeric($config, 'spread.minimum_metric_games'));
        $reliability = min(1.0, min($homeSample, $awaySample) / $minimumGames);
        $metricWeight = $this->bounded($this->numeric($config, 'spread.metric_weight') * $reliability, 0, 0.8);
        $margin = ($eloMargin * (1 - $metricWeight)) + ($metricMargin * $metricWeight);

        $homePower = $this->firstNumeric($homeMetrics, ['predictive_rating', 'power_rating', 'fpi']);
        $awayPower = $this->firstNumeric($awayMetrics, ['predictive_rating', 'power_rating', 'fpi']);
        $powerAdjustment = ($homePower - $awayPower) * $this->numeric($config, 'spread.power_rating_weight');
        $recentAdjustment = (
            $this->numericOr($homeMetrics, 'recent_form_rating', 0)
            - $this->numericOr($awayMetrics, 'recent_form_rating', 0)
        ) * $this->numeric($config, 'context.recent_spread_weight');
        $turnoverAdjustment = (
            $this->numericOr($homeMetrics, 'turnover_differential', 0)
            - $this->numericOr($awayMetrics, 'turnover_differential', 0)
        ) * $this->numeric($config, 'context.turnover_spread_weight');
        $fatigueAdjustment = (
            $this->numericOr($awayMetrics, 'rest_travel_fatigue', 0)
            - $this->numericOr($homeMetrics, 'rest_travel_fatigue', 0)
        ) * $this->numeric($config, 'context.fatigue_spread_weight');
        $homeInjuryRating = $this->numericOr($homeMetrics, 'injury_adjusted_team_rating', $homeElo);
        $awayInjuryRating = $this->numericOr($awayMetrics, 'injury_adjusted_team_rating', $awayElo);
        $injuryRatingAdjustment = (($homeInjuryRating - $awayInjuryRating) - ($homeElo - $awayElo))
            * $this->numeric($config, 'context.injury_rating_spread_weight');
        $homeInjuries = $this->injuryCounts((array) data_get($home, 'injuries', []));
        $awayInjuries = $this->injuryCounts((array) data_get($away, 'injuries', []));
        $availabilityAdjustment = $this->injurySpreadPenalty($awayInjuries, $config)
            - $this->injurySpreadPenalty($homeInjuries, $config);
        $unregressedMargin = $margin + $powerAdjustment + $recentAdjustment + $turnoverAdjustment
            + $fatigueAdjustment + $injuryRatingAdjustment + $availabilityAdjustment;
        $regression = $this->bounded($this->numeric($config, 'spread.output_regression_weight'), 0, 0.75);
        $homeMargin = round($unregressedMargin * (1 - $regression), 1);

        $rawTotal = $homeExpected + $awayExpected;
        $adjustedTotal = $rawTotal
            + (($this->numericOr($homeMetrics, 'recent_form_rating', 0)
                + $this->numericOr($awayMetrics, 'recent_form_rating', 0))
                * $this->numeric($config, 'context.recent_total_weight'))
            - (($this->numericOr($homeMetrics, 'rest_travel_fatigue', 0)
                + $this->numericOr($awayMetrics, 'rest_travel_fatigue', 0))
                * $this->numeric($config, 'context.fatigue_total_weight'))
            - $this->injuryTotalPenalty($homeInjuries, $awayInjuries, $config);
        $totalRegression = $this->bounded($this->numeric($config, 'total.output_regression_weight'), 0, 0.75);
        $projectedTotal = round(
            ($adjustedTotal * (1 - $totalRegression))
            + ($this->positive($config, 'total.average_total') * $totalRegression),
            1,
        );

        $homeProbability = round(1 / (1 + exp(-$homeMargin / $this->positive($config, 'spread.probability_coefficient'))), 6);
        $awayProbability = round(1 - $homeProbability, 6);
        $confidence = round(max($homeProbability, $awayProbability) * 100, 2);

        return new PredictionOutput(
            markets: [
                new PredictionMarketOutput('moneyline', 'home', probability: $homeProbability, confidenceScore: $confidence),
                new PredictionMarketOutput('moneyline', 'away', probability: $awayProbability, confidenceScore: $confidence),
                new PredictionMarketOutput('spread', 'home', projectedLine: -$homeMargin, confidenceScore: $confidence),
                new PredictionMarketOutput('total', 'combined', projectedLine: $projectedTotal, confidenceScore: $confidence),
            ],
            metadata: [
                'home_margin' => $homeMargin,
                'reason_codes' => [
                    $eloMargin >= 0 ? 'HOME_ELO_EDGE' : 'AWAY_ELO_EDGE',
                    $metricMargin >= 0 ? 'HOME_SCORING_EDGE' : 'AWAY_SCORING_EDGE',
                ],
                'market_conventions' => [
                    'spread' => 'sportsbook_home_line',
                    'total' => 'combined_points',
                    'moneyline_probability' => 'decimal_zero_to_one',
                ],
            ],
            diagnostics: [
                'elo_home_margin' => round($eloMargin, 4),
                'metric_home_margin' => round($metricMargin, 4),
                'metric_reliability' => round($reliability, 4),
                'power_adjustment' => round($powerAdjustment, 4),
                'recent_adjustment' => round($recentAdjustment, 4),
                'turnover_adjustment' => round($turnoverAdjustment, 4),
                'fatigue_adjustment' => round($fatigueAdjustment, 4),
                'injury_rating_adjustment' => round($injuryRatingAdjustment, 4),
                'availability_adjustment' => round($availabilityAdjustment, 4),
                'raw_total' => round($rawTotal, 4),
                'projected_total' => $projectedTotal,
                'home_injuries' => $homeInjuries,
                'away_injuries' => $awayInjuries,
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
    private function injurySpreadPenalty(array $counts, array $config): float
    {
        return ($counts['out'] * $this->numeric($config, 'injuries.out_spread_penalty'))
            + ($counts['questionable'] * $this->numeric($config, 'injuries.questionable_spread_penalty'));
    }

    /** @param array{out:int,questionable:int} $home @param array{out:int,questionable:int} $away @param array<string,mixed> $config */
    private function injuryTotalPenalty(array $home, array $away, array $config): float
    {
        return (($home['out'] + $away['out']) * $this->numeric($config, 'injuries.out_total_penalty'))
            + (($home['questionable'] + $away['questionable']) * $this->numeric($config, 'injuries.questionable_total_penalty'));
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

    /** @param array<string,mixed> $values @param list<string> $keys */
    private function firstNumeric(array $values, array $keys): float
    {
        foreach ($keys as $key) {
            if (is_numeric(data_get($values, $key))) {
                return (float) data_get($values, $key);
            }
        }

        return 0.0;
    }

    /** @param array<string,mixed> $values */
    private function positive(array $values, string $key): float
    {
        $value = $this->numeric($values, $key);
        if ($value <= 0) {
            throw new PredictionLifecycleException(strtoupper($this->expectedSport())." calculation input {$key} must be positive.");
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
