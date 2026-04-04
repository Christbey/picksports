<?php

namespace App\Services\NFL;

class PlayerFuturesBacktestService
{
    public function __construct(
        protected PlayerFuturesProjectionService $projectionService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(
        int $season,
        ?string $market = null,
        int $fromWeek = 1,
        ?int $toWeek = null,
        int $minSample = 5
    ): array {
        $actualTotals = $this->projectionService->actualSeasonTotals($season);
        $supportedMarkets = array_keys($this->projectionService->supportedMarkets());
        $selectedMarkets = $market !== null ? [$market] : $supportedMarkets;
        $maxWeek = (int) config('nfl.season.weeks.regular', 18);
        $fromWeek = max(1, $fromWeek);
        $toWeek = min($toWeek ?? max(1, $maxWeek - 1), max(1, $maxWeek - 1));

        $evaluationRows = [];
        $byWeek = [];

        foreach (range($fromWeek, $toWeek) as $week) {
            $weekProjections = $this->projectionService->projections(
                season: $season,
                market: $market,
                playerId: null,
                onlyWithOdds: false,
                sortBy: 'projected_total',
                direction: 'desc',
                limit: 5000,
                asOfWeek: $week,
            );

            $weekRows = [];

            foreach ($weekProjections as $projection) {
                $playerId = (int) ($projection['player_id'] ?? 0);
                $marketKey = (string) ($projection['market'] ?? '');

                if ($playerId <= 0 || $marketKey === '' || ! in_array($marketKey, $selectedMarkets, true)) {
                    continue;
                }

                $actualTotal = $actualTotals[$playerId][$this->statFieldForMarket($marketKey)] ?? null;
                if ($actualTotal === null) {
                    continue;
                }

                $line = isset($projection['market_odds']['line']) && is_numeric($projection['market_odds']['line'])
                    ? (float) $projection['market_odds']['line']
                    : null;
                $overProbability = isset($projection['over_probability']) && is_numeric($projection['over_probability'])
                    ? (float) $projection['over_probability']
                    : null;
                $actualOver = $line !== null ? ((float) $actualTotal > $line ? 1.0 : 0.0) : null;

                $row = [
                    'week' => $week,
                    'player_id' => $playerId,
                    'market' => $marketKey,
                    'projected_total' => (float) ($projection['projected_total'] ?? 0.0),
                    'actual_total' => (float) $actualTotal,
                    'line' => $line,
                    'over_probability' => $overProbability,
                    'actual_over' => $actualOver,
                ];

                $weekRows[] = $row;
                $evaluationRows[] = $row;
            }

            $byWeek[] = [
                'week' => $week,
                'summary' => $this->summarize($weekRows),
            ];
        }

        $byMarket = [];
        foreach ($selectedMarkets as $marketKey) {
            $marketRows = array_values(array_filter(
                $evaluationRows,
                static fn (array $row): bool => $row['market'] === $marketKey
            ));

            if (count($marketRows) < $minSample) {
                continue;
            }

            $byMarket[] = [
                'market' => $marketKey,
                'summary' => $this->summarize($marketRows),
            ];
        }

        return [
            'report_type' => 'nfl_player_futures_backtest',
            'season' => $season,
            'from_week' => $fromWeek,
            'to_week' => $toWeek,
            'summary' => $this->summarize($evaluationRows),
            'weeks' => $byWeek,
            'markets' => $byMarket,
        ];
    }

    protected function statFieldForMarket(string $market): string
    {
        return (string) data_get($this->projectionService->supportedMarkets(), "{$market}.stat_field", $market);
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
