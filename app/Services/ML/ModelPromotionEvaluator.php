<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use Illuminate\Support\Facades\File;

class ModelPromotionEvaluator
{
    public function __construct(
        private readonly EvaluationReportNormalizer $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function evaluate(ModelArtifact $artifact, string $reportPath, array $criteria = []): array
    {
        $report = json_decode((string) File::get($reportPath), true);
        if (! is_array($report)) {
            throw new \RuntimeException('Rolling evaluation report is invalid.');
        }

        $normalized = $this->reports->normalize($report);
        $summary = $normalized['raw_summary'];
        $windows = $normalized['raw_windows'];
        $reportedConvention = $normalized['delta_convention']['reported'];
        $minimumWindows = max(2, (int) ($criteria['minimum_windows']
            ?? config('ml.promotion.minimum_windows', 3)));
        $minimumWinRate = min(1.0, max(0.5, (float) ($criteria['minimum_better_window_rate']
            ?? config('ml.promotion.minimum_better_window_rate', 0.6))));
        $maximumRegressions = [
            'brier' => max(0.0, (float) ($criteria['maximum_brier_regression']
                ?? config('ml.promotion.maximum_worst_window_regression.brier', 0.02))),
            'log_loss' => max(0.0, (float) ($criteria['maximum_log_loss_regression']
                ?? config('ml.promotion.maximum_worst_window_regression.log_loss', 0.10))),
            'mae' => max(0.0, (float) ($criteria['maximum_mae_regression']
                ?? config('ml.promotion.maximum_worst_window_regression.mae', 1.0))),
        ];

        $markets = [];
        foreach ($this->marketsFor($artifact) as $market) {
            $markets[$market] = $this->evaluateMarket(
                market: $market,
                summary: $summary,
                windows: $windows,
                reportedConvention: $reportedConvention,
                minimumWindows: $minimumWindows,
                minimumWinRate: $minimumWinRate,
                maximumRegressions: $maximumRegressions,
            );
        }

        $eligibleMarkets = array_keys(array_filter(
            $markets,
            fn (array $market): bool => (bool) $market['eligible'],
        ));
        $availableMarkets = array_keys(array_filter(
            $markets,
            fn (array $market): bool => (bool) $market['available'],
        ));
        $checks = [];
        foreach ($markets as $market => $decision) {
            foreach ($decision['checks'] as $gate => $passed) {
                $checks[$market.'.'.$gate] = $passed;
            }
        }

        return [
            'eligible' => $eligibleMarkets !== [],
            'eligible_markets' => $eligibleMarkets,
            'available_markets' => $availableMarkets,
            'markets' => $markets,
            'checks' => $checks,
            'window_count' => (int) ($summary['window_count'] ?? count($windows)),
            'delta_convention' => $normalized['delta_convention'],
            'promotion_summary' => $normalized['promotion_summary'],
            'criteria' => [
                'minimum_windows' => $minimumWindows,
                'minimum_better_window_rate' => $minimumWinRate,
                'maximum_worst_window_regression' => $maximumRegressions,
                'normalized_delta_convention' => EvaluationReportNormalizer::BASELINE_MINUS_CHALLENGER,
                'positive_delta_means' => 'challenger_better',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function marketsFor(ModelArtifact $artifact): array
    {
        return match (ModelArtifact::normalizeMarketType($artifact->market_type)) {
            'multi_market' => ['win_probability', 'spread', 'total'],
            'spread' => ['spread'],
            'total' => ['total'],
            default => ['win_probability'],
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $windows
     * @param  array{brier:float,log_loss:float,mae:float}  $maximumRegressions
     * @return array<string, mixed>
     */
    private function evaluateMarket(
        string $market,
        array $summary,
        array $windows,
        string $reportedConvention,
        int $minimumWindows,
        float $minimumWinRate,
        array $maximumRegressions,
    ): array {
        $windowDeltas = array_values(array_filter(array_map(
            fn (array $window): ?array => $this->windowDeltas($market, $window, $reportedConvention),
            $windows,
        )));
        $windowCount = count($windowDeltas);
        $primaryKey = $market === 'win_probability' ? 'brier' : 'mae';
        $secondaryKey = $market === 'win_probability' ? 'log_loss' : null;
        $primaryDeltas = array_column($windowDeltas, $primaryKey);
        $secondaryDeltas = $secondaryKey ? array_column($windowDeltas, $secondaryKey) : [];
        $summaryPrimary = $this->summaryDelta($market, $summary, $reportedConvention);
        $summarySecondary = $market === 'win_probability'
            ? $this->reports->improvementDelta($summary['avg_log_loss_delta'] ?? null, $reportedConvention)
            : null;
        $averagePrimary = $this->average($primaryDeltas) ?? $summaryPrimary;
        $averageSecondary = $this->average($secondaryDeltas) ?? $summarySecondary;
        $betterWindows = $windowCount > 0
            ? count(array_filter(
                $windowDeltas,
                fn (array $delta): bool => $delta[$primaryKey] > 0.0
                    && ($secondaryKey === null || $delta[$secondaryKey] > 0.0),
            ))
            : (int) ($market === 'win_probability'
                ? ($summary['challenger_better_window_count'] ?? 0)
                : 0);
        $effectiveWindowCount = $windowCount > 0
            ? $windowCount
            : (int) ($summary['window_count'] ?? 0);
        $betterWindowRate = $effectiveWindowCount > 0
            ? $betterWindows / $effectiveWindowCount
            : 0.0;
        $worstPrimaryRegression = $this->worstRegression($primaryDeltas);
        $worstSecondaryRegression = $secondaryKey
            ? $this->worstRegression($secondaryDeltas)
            : null;
        $maximumPrimaryRegression = $maximumRegressions[$primaryKey];
        $maximumSecondaryRegression = $secondaryKey
            ? $maximumRegressions[$secondaryKey]
            : null;
        $available = $averagePrimary !== null && $windowCount > 0;

        $checks = [
            'metrics_available' => $available,
            'minimum_windows' => $effectiveWindowCount >= $minimumWindows,
            'better_window_rate' => $betterWindowRate >= $minimumWinRate,
            'positive_average_primary_metric' => $averagePrimary !== null && $averagePrimary > 0.0,
            'positive_average_secondary_metric' => $secondaryKey === null
                || ($averageSecondary !== null && $averageSecondary > 0.0),
            'worst_primary_window_regression' => $worstPrimaryRegression !== null
                && $worstPrimaryRegression <= $maximumPrimaryRegression,
            'worst_secondary_window_regression' => $secondaryKey === null
                || ($worstSecondaryRegression !== null
                    && $worstSecondaryRegression <= $maximumSecondaryRegression),
        ];

        return [
            'available' => $available,
            'eligible' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'window_count' => $effectiveWindowCount,
            'challenger_better_window_count' => $betterWindows,
            'challenger_better_window_rate' => $betterWindowRate,
            'average_primary_improvement' => $averagePrimary,
            'average_secondary_improvement' => $averageSecondary,
            'worst_primary_window_regression' => $worstPrimaryRegression,
            'worst_secondary_window_regression' => $worstSecondaryRegression,
            'maximum_primary_window_regression' => $maximumPrimaryRegression,
            'maximum_secondary_window_regression' => $maximumSecondaryRegression,
        ];
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array<string, float>|null
     */
    private function windowDeltas(string $market, array $window, string $reportedConvention): ?array
    {
        if ($market === 'win_probability') {
            $champion = data_get($window, 'champion_classifier');
            $challengerBrier = is_string($champion)
                ? data_get($window, "classifiers.{$champion}.test_calibrated.brier")
                : null;
            $baselineBrier = data_get($window, 'baselines.classifiers.current_picksports.brier');
            $challengerLogLoss = is_string($champion)
                ? data_get($window, "classifiers.{$champion}.test_calibrated.log_loss")
                : null;
            $baselineLogLoss = data_get($window, 'baselines.classifiers.current_picksports.log_loss');

            if (is_numeric($challengerBrier) && is_numeric($baselineBrier)
                && is_numeric($challengerLogLoss) && is_numeric($baselineLogLoss)) {
                return [
                    'brier' => (float) $baselineBrier - (float) $challengerBrier,
                    'log_loss' => (float) $baselineLogLoss - (float) $challengerLogLoss,
                ];
            }

            $brier = $this->reports->improvementDelta($window['brier_delta'] ?? null, $reportedConvention);
            $logLoss = $this->reports->improvementDelta($window['log_loss_delta'] ?? null, $reportedConvention);

            return $brier !== null && $logLoss !== null
                ? ['brier' => $brier, 'log_loss' => $logLoss]
                : null;
        }

        $challengerPath = $market === 'spread'
            ? 'regressors.home_margin.mae'
            : 'regressors.total_points.mae';
        $baselinePath = $market === 'spread'
            ? 'baselines.regressors.current_picksports_home_margin.mae'
            : 'baselines.regressors.current_picksports_total_points.mae';
        $legacyKey = $market === 'spread' ? 'spread_mae_delta' : 'total_mae_delta';
        $challenger = data_get($window, $challengerPath);
        $baseline = data_get($window, $baselinePath);

        if (is_numeric($challenger) && is_numeric($baseline)) {
            return ['mae' => (float) $baseline - (float) $challenger];
        }

        $mae = $this->reports->improvementDelta($window[$legacyKey] ?? null, $reportedConvention);

        return $mae !== null ? ['mae' => $mae] : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summaryDelta(string $market, array $summary, string $reportedConvention): ?float
    {
        $key = match ($market) {
            'win_probability' => 'avg_brier_delta',
            'spread' => 'avg_spread_mae_delta',
            'total' => 'avg_total_mae_delta',
        };

        return $this->reports->improvementDelta($summary[$key] ?? null, $reportedConvention);
    }

    /**
     * @param  list<float|int>  $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @param  list<float|int>  $improvements
     */
    private function worstRegression(array $improvements): ?float
    {
        return $improvements === []
            ? null
            : max(0.0, -(float) min($improvements));
    }
}
