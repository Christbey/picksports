<?php

namespace App\Services\ML;

class EvaluationReportNormalizer
{
    public const BASELINE_MINUS_CHALLENGER = 'baseline_minus_challenger';

    public const CHALLENGER_MINUS_BASELINE = 'challenger_minus_baseline';

    /**
     * @param  array<string, mixed>  $report
     * @return array{
     *     summary:array<string,mixed>,
     *     raw_summary:array<string,mixed>,
     *     windows:list<array<string,mixed>>,
     *     raw_windows:list<array<string,mixed>>,
     *     promotion_summary:array<string,mixed>,
     *     delta_convention:array{reported:string,normalized:string,positive_means:string,source:string}
     * }
     */
    public function normalize(array $report): array
    {
        $walkForwardSummary = data_get($report, 'walk_forward.summary');
        $walkForwardWindows = data_get($report, 'walk_forward.windows');
        $rollingWeeklySummary = data_get($report, 'rolling_weekly.summary');
        $rollingWeeklyWindows = data_get($report, 'rolling_weekly.windows');
        $usesWalkForwardLayout = is_array($walkForwardSummary) || is_array($walkForwardWindows);
        $usesRollingWeeklyLayout = is_array($rollingWeeklySummary) || is_array($rollingWeeklyWindows);
        $convention = $this->deltaConvention(
            $report,
            $usesWalkForwardLayout,
            $usesRollingWeeklyLayout,
        );
        $rawWindows = array_values(is_array($walkForwardWindows)
            ? $walkForwardWindows
            : (is_array($rollingWeeklyWindows)
                ? $rollingWeeklyWindows
                : (array) ($report['windows'] ?? [])));
        $rawSummary = is_array($walkForwardSummary)
            ? $walkForwardSummary
            : (is_array($rollingWeeklySummary)
                ? $rollingWeeklySummary
                : (array) ($report['summary'] ?? []));

        return [
            'summary' => $this->normalizeSummary($rawSummary, $convention['convention']),
            'raw_summary' => $rawSummary,
            'windows' => array_map(
                fn (array $window): array => $this->normalizeWindow($window, $convention['convention']),
                $rawWindows,
            ),
            'raw_windows' => $rawWindows,
            'promotion_summary' => (array) ($report['promotion_summary'] ?? []),
            'delta_convention' => [
                'reported' => $convention['convention'],
                'normalized' => self::BASELINE_MINUS_CHALLENGER,
                'positive_means' => 'challenger_better',
                'source' => $convention['source'],
            ],
        ];
    }

    public function improvementDelta(mixed $value, string $reportedConvention): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $delta = (float) $value;

        return round($reportedConvention === self::CHALLENGER_MINUS_BASELINE
            ? -$delta
            : $delta, 12);
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function normalizeWindow(array $window, string $reportedConvention): array
    {
        $champion = data_get($window, 'champion_classifier');
        $championPrefix = is_string($champion)
            ? "classifiers.{$champion}.test_calibrated"
            : null;
        $baselineBrier = data_get($window, 'baselines.classifiers.current_picksports.brier')
            ?? ($window['baseline_brier'] ?? null);
        $challengerBrier = ($championPrefix
            ? data_get($window, "{$championPrefix}.brier")
            : null) ?? ($window['challenger_brier'] ?? null);
        $baselineLogLoss = data_get($window, 'baselines.classifiers.current_picksports.log_loss')
            ?? ($window['baseline_log_loss'] ?? null);
        $challengerLogLoss = ($championPrefix
            ? data_get($window, "{$championPrefix}.log_loss")
            : null) ?? ($window['challenger_log_loss'] ?? null);
        $baselineSpreadMae = data_get($window, 'baselines.regressors.current_picksports_home_margin.mae')
            ?? ($window['baseline_spread_mae'] ?? null);
        $challengerSpreadMae = data_get($window, 'regressors.home_margin.mae')
            ?? ($window['challenger_spread_mae'] ?? null);
        $baselineTotalMae = data_get($window, 'baselines.regressors.current_picksports_total_points.mae')
            ?? ($window['baseline_total_mae'] ?? null);
        $challengerTotalMae = data_get($window, 'regressors.total_points.mae')
            ?? ($window['challenger_total_mae'] ?? null);

        return [
            'evaluation_season' => $window['evaluation_season'] ?? $window['test_season'] ?? null,
            'games' => $window['games']
                ?? ($championPrefix ? data_get($window, "{$championPrefix}.count") : null)
                ?? $window['evaluation_rows']
                ?? null,
            'baseline_brier' => $this->numeric($baselineBrier),
            'challenger_brier' => $this->numeric($challengerBrier),
            'brier_delta' => $this->metricImprovement(
                $baselineBrier,
                $challengerBrier,
                $window['brier_delta'] ?? null,
                $reportedConvention,
            ),
            'baseline_log_loss' => $this->numeric($baselineLogLoss),
            'challenger_log_loss' => $this->numeric($challengerLogLoss),
            'log_loss_delta' => $this->metricImprovement(
                $baselineLogLoss,
                $challengerLogLoss,
                $window['log_loss_delta'] ?? null,
                $reportedConvention,
            ),
            'baseline_spread_mae' => $this->numeric($baselineSpreadMae),
            'challenger_spread_mae' => $this->numeric($challengerSpreadMae),
            'spread_mae_delta' => $this->metricImprovement(
                $baselineSpreadMae,
                $challengerSpreadMae,
                $window['spread_mae_delta'] ?? null,
                $reportedConvention,
            ),
            'baseline_total_mae' => $this->numeric($baselineTotalMae),
            'challenger_total_mae' => $this->numeric($challengerTotalMae),
            'total_mae_delta' => $this->metricImprovement(
                $baselineTotalMae,
                $challengerTotalMae,
                $window['total_mae_delta'] ?? null,
                $reportedConvention,
            ),
            'delta_convention' => self::BASELINE_MINUS_CHALLENGER,
            'raw' => $window,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function normalizeSummary(array $summary, string $reportedConvention): array
    {
        foreach ([
            'avg_brier_delta',
            'avg_log_loss_delta',
            'avg_spread_mae_delta',
            'avg_total_mae_delta',
        ] as $key) {
            if (array_key_exists($key, $summary)) {
                $summary[$key] = $this->improvementDelta($summary[$key], $reportedConvention);
            }
        }

        $summary['delta_convention'] = self::BASELINE_MINUS_CHALLENGER;

        return $summary;
    }

    private function metricImprovement(
        mixed $baseline,
        mixed $challenger,
        mixed $reportedDelta,
        string $reportedConvention,
    ): ?float {
        if (is_numeric($baseline) && is_numeric($challenger)) {
            return round((float) $baseline - (float) $challenger, 12);
        }

        return $this->improvementDelta($reportedDelta, $reportedConvention);
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{convention:string,source:string}
     */
    private function deltaConvention(
        array $report,
        bool $usesWalkForwardLayout,
        bool $usesRollingWeeklyLayout,
    ): array {
        $declared = data_get($report, 'delta_convention')
            ?? data_get($report, 'summary.delta_convention')
            ?? data_get($report, 'walk_forward.summary.delta_convention');

        if (is_string($declared)) {
            $normalized = str_replace('-', '_', strtolower(trim($declared)));
            if (in_array($normalized, [
                self::BASELINE_MINUS_CHALLENGER,
                self::CHALLENGER_MINUS_BASELINE,
            ], true)) {
                return [
                    'convention' => $normalized,
                    'source' => 'report_declaration',
                ];
            }
        }

        if ($usesWalkForwardLayout) {
            return [
                'convention' => self::BASELINE_MINUS_CHALLENGER,
                'source' => 'walk_forward_layout',
            ];
        }

        if ($usesRollingWeeklyLayout) {
            return [
                'convention' => self::BASELINE_MINUS_CHALLENGER,
                'source' => 'rolling_weekly_layout',
            ];
        }

        return [
            'convention' => self::CHALLENGER_MINUS_BASELINE,
            'source' => 'legacy_laravel_layout',
        ];
    }
}
