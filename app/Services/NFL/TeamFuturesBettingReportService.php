<?php

namespace App\Services\NFL;

use Carbon\Carbon;

class TeamFuturesBettingReportService
{
    public function __construct(
        protected TeamFuturesProjectionService $projectionService,
        protected TeamFuturesBacktestService $backtestService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(
        int $season,
        string $market = 'season_wins',
        ?string $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        float $minEdge = 0.0,
        int $limit = 10
    ): array {
        $targetDate = $asOfDate !== null ? Carbon::parse($asOfDate) : null;

        $rows = $this->projectionService->projections(
            season: $season,
            market: $market,
            asOfDate: $targetDate,
            requireHistoricalMetrics: $requireHistoricalMetrics,
            onlyWithOdds: true,
            sortBy: 'projected_total',
            direction: 'desc',
            limit: 200,
        );
        $calibration = $this->calibrationProfile(
            season: $season,
            market: $market,
            asOfDate: $targetDate?->toIso8601String(),
            requireHistoricalMetrics: $requireHistoricalMetrics,
        );

        $bets = [];

        foreach ($rows as $row) {
            $marketOdds = $row['market_odds'] ?? null;
            if (! is_array($marketOdds)) {
                continue;
            }

            $overPrice = $this->numericOrNull($marketOdds['over_price'] ?? null);
            $underPrice = $this->numericOrNull($marketOdds['under_price'] ?? null);
            $overProbability = $this->numericOrNull($row['over_probability'] ?? null);
            $underProbability = $this->numericOrNull($row['under_probability'] ?? null);
            $marketOverProbability = $this->numericOrNull($marketOdds['over_implied_probability'] ?? null);
            $marketUnderProbability = $this->numericOrNull($marketOdds['under_implied_probability'] ?? null);

            $candidates = [];
            if ($overPrice !== null && $overProbability !== null && $marketOverProbability !== null) {
                $candidates[] = $this->candidate(
                    row: $row,
                    side: 'over',
                    modelProbability: $overProbability,
                    calibratedProbability: $this->calibrateProbability($overProbability, $calibration),
                    marketProbability: $marketOverProbability,
                    price: $overPrice
                );
            }

            if ($underPrice !== null && $underProbability !== null && $marketUnderProbability !== null) {
                $candidates[] = $this->candidate(
                    row: $row,
                    side: 'under',
                    modelProbability: $underProbability,
                    calibratedProbability: $this->calibrateProbability($underProbability, $calibration),
                    marketProbability: $marketUnderProbability,
                    price: $underPrice
                );
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn (array $a, array $b): int => $b['edge'] <=> $a['edge']);
            $best = $candidates[0];

            if ((float) $best['edge'] < $minEdge) {
                continue;
            }

            $bets[] = $best;
        }

        usort($bets, fn (array $a, array $b): int => $b['edge'] <=> $a['edge']);
        $bets = array_slice($bets, 0, max(1, $limit));

        return [
            'report_type' => 'nfl_team_futures_bets',
            'season' => $season,
            'market' => $market,
            'as_of_date' => $targetDate?->toIso8601String(),
            'require_historical_metrics' => $requireHistoricalMetrics,
            'min_edge' => round($minEdge, 4),
            'calibration' => $calibration,
            'summary' => [
                'count' => count($bets),
                'average_edge' => $bets === [] ? null : round(array_sum(array_column($bets, 'edge')) / count($bets), 4),
                'average_expected_value' => $bets === [] ? null : round(array_sum(array_column($bets, 'expected_value')) / count($bets), 4),
                'average_kelly_fraction' => $bets === [] ? null : round(array_sum(array_column($bets, 'kelly_fraction')) / count($bets), 4),
            ],
            'bets' => $bets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function candidate(
        array $row,
        string $side,
        float $modelProbability,
        float $calibratedProbability,
        float $marketProbability,
        float $price
    ): array {
        $edge = $calibratedProbability - $marketProbability;
        $profitMultiple = $this->profitMultiple($price);
        $expectedValue = ($calibratedProbability * $profitMultiple) - (1.0 - $calibratedProbability);
        $kellyFraction = $this->kellyFraction($calibratedProbability, $profitMultiple);

        return [
            'team_id' => (int) ($row['team_id'] ?? 0),
            'team_name' => (string) data_get($row, 'market_odds.team_name', (string) ($row['team_id'] ?? '')),
            'side' => $side,
            'market' => (string) ($row['market'] ?? ''),
            'line' => $this->numericOrNull(data_get($row, 'market_odds.line')),
            'price' => (int) round($price),
            'projected_total' => round((float) ($row['projected_total'] ?? 0.0), 3),
            'raw_model_probability' => round($modelProbability, 4),
            'model_probability' => round($calibratedProbability, 4),
            'market_probability' => round($marketProbability, 4),
            'edge' => round($edge, 4),
            'expected_value' => round($expectedValue, 4),
            'fair_price' => $this->fairAmericanOdds($calibratedProbability),
            'kelly_fraction' => round($kellyFraction, 4),
            'captured_at' => data_get($row, 'market_odds.captured_at'),
            'projection_factors' => $row['projection_factors'] ?? [],
        ];
    }

    /**
     * @return array<string, float|int|string|bool|null>
     */
    protected function calibrationProfile(
        int $season,
        string $market,
        ?string $asOfDate,
        bool $requireHistoricalMetrics
    ): array {
        $defaultShrink = max(0.0, min(1.0, (float) config('nfl.team_futures.betting_probability_calibration.default_shrink', 0.7)));
        $minSample = max(1, (int) config('nfl.team_futures.betting_probability_calibration.min_sample', 20));
        $minShrink = max(0.0, min(1.0, (float) config('nfl.team_futures.betting_probability_calibration.min_shrink', 0.2)));
        $maxShrink = max($minShrink, min(1.0, (float) config('nfl.team_futures.betting_probability_calibration.max_shrink', 1.0)));
        $step = max(0.01, (float) config('nfl.team_futures.betting_probability_calibration.step', 0.05));

        [$rows] = $this->backtestService->evaluationDataset(
            season: $season,
            market: $market,
            fromDate: null,
            toDate: $asOfDate,
            requireHistoricalMetrics: $requireHistoricalMetrics,
        );

        $samples = array_values(array_filter($rows, fn (array $row): bool => $row['over_probability'] !== null && $row['actual_over'] !== null));
        if (count($samples) < $minSample) {
            return [
                'enabled' => true,
                'method' => 'default_shrink',
                'sample_size' => count($samples),
                'shrink_factor' => round($defaultShrink, 4),
            ];
        }

        $bestShrink = $defaultShrink;
        $bestBrier = INF;

        for ($shrink = $minShrink; $shrink <= $maxShrink + 1e-9; $shrink += $step) {
            $brier = 0.0;

            foreach ($samples as $sample) {
                $probability = $this->calibrateProbability((float) $sample['over_probability'], ['shrink_factor' => $shrink]);
                $actual = (float) $sample['actual_over'];
                $brier += ($probability - $actual) ** 2;
            }

            $avgBrier = $brier / count($samples);
            if ($avgBrier < $bestBrier) {
                $bestBrier = $avgBrier;
                $bestShrink = $shrink;
            }
        }

        return [
            'enabled' => true,
            'method' => 'fit_shrink',
            'sample_size' => count($samples),
            'shrink_factor' => round($bestShrink, 4),
            'fitted_brier' => round($bestBrier, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $calibration
     */
    protected function calibrateProbability(float $probability, array $calibration): float
    {
        $shrink = max(0.0, min(1.0, (float) ($calibration['shrink_factor'] ?? 1.0)));

        return max(0.0001, min(0.9999, 0.5 + (($probability - 0.5) * $shrink)));
    }

    protected function profitMultiple(float $price): float
    {
        if ($price > 0) {
            return $price / 100.0;
        }

        if ($price < 0) {
            return 100.0 / abs($price);
        }

        return 1.0;
    }

    protected function fairAmericanOdds(float $probability): int
    {
        $probability = max(0.0001, min(0.9999, $probability));

        if ($probability >= 0.5) {
            return (int) round((-100 * $probability) / (1 - $probability));
        }

        return (int) round((100 * (1 - $probability)) / $probability);
    }

    protected function kellyFraction(float $probability, float $profitMultiple): float
    {
        if ($profitMultiple <= 0.0) {
            return 0.0;
        }

        $rawKelly = (($profitMultiple * $probability) - (1.0 - $probability)) / $profitMultiple;
        $scaledKelly = max(0.0, $rawKelly) * (float) config('nfl.betting.kelly.fraction', 0.25);

        return min($scaledKelly, ((float) config('nfl.betting.kelly.max_percent', 5.0)) / 100.0);
    }

    protected function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
