<?php

namespace App\Services\NFL;

use Carbon\Carbon;

class TeamFuturesBacktestService
{
    public function __construct(
        protected TeamFuturesProjectionService $projectionService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(
        int $season,
        string $market = 'season_wins',
        ?string $fromDate = null,
        ?string $toDate = null,
        int $minSample = 5,
        bool $requireHistoricalMetrics = false
    ): array {
        [$evaluationRows, $byDate, $selectedDates] = $this->evaluationDataset(
            season: $season,
            market: $market,
            fromDate: $fromDate,
            toDate: $toDate,
            requireHistoricalMetrics: $requireHistoricalMetrics,
        );

        $markets = [];
        if (count($evaluationRows) >= $minSample) {
            $markets[] = [
                'market' => $market,
                'summary' => $this->summarize($evaluationRows),
            ];
        }

        return [
            'report_type' => 'nfl_team_futures_backtest',
            'season' => $season,
            'market' => $market,
            'require_historical_metrics' => $requireHistoricalMetrics,
            'from_date' => $selectedDates[0] ?? $fromDate,
            'to_date' => $selectedDates !== [] ? $selectedDates[array_key_last($selectedDates)] : $toDate,
            'summary' => $this->summarize($evaluationRows),
            'dates' => $byDate,
            'markets' => $markets,
        ];
    }

    /**
     * @return array{0:array<int, array<string, mixed>>,1:array<int, array<string, mixed>>,2:array<int, string>}
     */
    public function evaluationDataset(
        int $season,
        string $market = 'season_wins',
        ?string $fromDate = null,
        ?string $toDate = null,
        bool $requireHistoricalMetrics = false
    ): array {
        $actualTotals = $this->projectionService->actualSeasonTotals($season, $market);
        $marketConfig = $this->projectionService->supportedMarkets()[$market] ?? null;
        if ($marketConfig === null) {
            return [[], [], []];
        }

        $dates = app(\App\Services\Sports\FuturesOddsLookupService::class)->snapshotDatesForSeasonMarket(
            'nfl',
            $season,
            (array) ($marketConfig['odds_market_keys'] ?? [])
        );

        $selectedDates = array_values(array_filter($dates, function (string $date) use ($fromDate, $toDate): bool {
            $timestamp = Carbon::parse($date);

            if ($fromDate !== null && $fromDate !== '' && $timestamp->lt(Carbon::parse($fromDate))) {
                return false;
            }

            if ($toDate !== null && $toDate !== '' && $timestamp->gt(Carbon::parse($toDate))) {
                return false;
            }

            return true;
        }));

        $evaluationRows = [];
        $byDate = [];

        foreach ($selectedDates as $date) {
            $projections = $this->projectionService->projections(
                season: $season,
                market: $market,
                asOfDate: $date,
                requireHistoricalMetrics: $requireHistoricalMetrics,
                onlyWithOdds: true,
                sortBy: 'projected_total',
                direction: 'desc',
                limit: 100,
            );

            $dateRows = [];

            foreach ($projections as $projection) {
                $teamId = (int) ($projection['team_id'] ?? 0);
                if ($teamId <= 0 || ! array_key_exists($teamId, $actualTotals)) {
                    continue;
                }

                $line = isset($projection['market_odds']['line']) && is_numeric($projection['market_odds']['line'])
                    ? (float) $projection['market_odds']['line']
                    : null;
                $overProbability = isset($projection['over_probability']) && is_numeric($projection['over_probability'])
                    ? (float) $projection['over_probability']
                    : null;
                $actualTotal = (float) $actualTotals[$teamId];
                $actualOver = $line !== null ? ($actualTotal > $line ? 1.0 : 0.0) : null;

                $row = [
                    'date' => $date,
                    'team_id' => $teamId,
                    'market' => $market,
                    'projected_total' => (float) ($projection['projected_total'] ?? 0.0),
                    'actual_total' => $actualTotal,
                    'line' => $line,
                    'over_probability' => $overProbability,
                    'actual_over' => $actualOver,
                ];

                $dateRows[] = $row;
                $evaluationRows[] = $row;
            }

            $byDate[] = [
                'date' => $date,
                'summary' => $this->summarize($dateRows),
            ];
        }

        return [$evaluationRows, $byDate, $selectedDates];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, float|int|null>
     */
    protected function summarize(array $rows): array
    {
        $count = count($rows);
        if ($count === 0) {
            return [
                'count' => 0,
                'mae' => null,
                'rmse' => null,
                'bias' => null,
                'line_count' => 0,
                'over_accuracy' => null,
                'over_brier' => null,
            ];
        }

        $absError = 0.0;
        $sqError = 0.0;
        $bias = 0.0;
        $lineCount = 0;
        $overCorrect = 0.0;
        $overBrier = 0.0;

        foreach ($rows as $row) {
            $error = (float) $row['projected_total'] - (float) $row['actual_total'];
            $absError += abs($error);
            $sqError += $error ** 2;
            $bias += $error;

            if ($row['line'] !== null && $row['over_probability'] !== null && $row['actual_over'] !== null) {
                $lineCount++;
                $predictedOver = (float) $row['over_probability'] >= 0.5 ? 1.0 : 0.0;
                $actualOver = (float) $row['actual_over'];

                if ($predictedOver === $actualOver) {
                    $overCorrect++;
                }

                $diff = (float) $row['over_probability'] - $actualOver;
                $overBrier += $diff ** 2;
            }
        }

        return [
            'count' => $count,
            'mae' => round($absError / $count, 3),
            'rmse' => round(sqrt($sqError / $count), 3),
            'bias' => round($bias / $count, 3),
            'line_count' => $lineCount,
            'over_accuracy' => $lineCount > 0 ? round($overCorrect / $lineCount, 4) : null,
            'over_brier' => $lineCount > 0 ? round($overBrier / $lineCount, 4) : null,
        ];
    }
}
