<?php

namespace App\Services\ML;

class RollingWinProbabilityEvaluator
{
    /**
     * @param  list<array<string, string>>  $rows
     * @return array{summary:array<string,float|int>,windows:list<array<string,mixed>>}
     */
    public function evaluateBySeason(
        array $rows,
        WinProbabilityCalibrationTrainer $trainer,
        int $minTrainSize,
        float $learningRate,
        int $iterations,
    ): array {
        usort($rows, fn (array $left, array $right): int => [
            $left['season'] ?? '',
            $left['game_start_at'] ?? $left['game_date'] ?? '',
            $left['game_id'] ?? '',
        ] <=> [
            $right['season'] ?? '',
            $right['game_start_at'] ?? $right['game_date'] ?? '',
            $right['game_id'] ?? '',
        ]);

        $seasons = collect($rows)
            ->pluck('season')
            ->filter(fn (mixed $season): bool => $season !== null && $season !== '')
            ->map(fn (mixed $season): int => (int) $season)
            ->unique()
            ->sort()
            ->values();
        $windows = [];

        foreach ($seasons as $season) {
            $trainRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => (int) ($row['season'] ?? 0) < $season,
            ));
            $evaluationRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => (int) ($row['season'] ?? 0) === $season,
            ));

            if (count($trainRows) < $minTrainSize || $evaluationRows === []) {
                continue;
            }

            $model = $trainer->train($trainRows, $learningRate, $iterations);
            $windows[] = [
                'window' => count($windows) + 1,
                'evaluation_season' => $season,
                'train_rows' => count($trainRows),
                'evaluation_rows' => count($evaluationRows),
                'train_range' => [
                    'start' => $trainRows[0]['game_start_at'] ?? $trainRows[0]['game_date'] ?? null,
                    'end' => $trainRows[array_key_last($trainRows)]['game_start_at']
                        ?? $trainRows[array_key_last($trainRows)]['game_date']
                        ?? null,
                ],
                'evaluation_range' => [
                    'start' => $evaluationRows[0]['game_start_at'] ?? $evaluationRows[0]['game_date'] ?? null,
                    'end' => $evaluationRows[array_key_last($evaluationRows)]['game_start_at']
                        ?? $evaluationRows[array_key_last($evaluationRows)]['game_date']
                        ?? null,
                ],
                'model' => $model,
                'metrics' => $trainer->evaluate($evaluationRows, $model),
            ];
        }

        return [
            'summary' => $this->summarize($windows),
            'windows' => $windows,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{summary:array<string,float|int>,windows:list<array<string,mixed>>}
     */
    public function evaluate(
        array $rows,
        WinProbabilityCalibrationTrainer $trainer,
        int $minTrainSize,
        int $testWindowSize,
        int $stepSize,
        float $learningRate,
        int $iterations,
    ): array {
        usort($rows, fn (array $left, array $right): int => [
            $left['game_start_at'] ?? $left['game_date'] ?? '',
            $left['game_id'] ?? '',
        ] <=> [
            $right['game_start_at'] ?? $right['game_date'] ?? '',
            $right['game_id'] ?? '',
        ]);

        $windows = [];
        for ($trainEnd = $minTrainSize; $trainEnd < count($rows); $trainEnd += $stepSize) {
            $trainRows = array_slice($rows, 0, $trainEnd);
            $evaluationRows = array_slice($rows, $trainEnd, $testWindowSize);
            if ($evaluationRows === []) {
                break;
            }

            $model = $trainer->train($trainRows, $learningRate, $iterations);
            $metrics = $trainer->evaluate($evaluationRows, $model);
            $windows[] = [
                'window' => count($windows) + 1,
                'train_rows' => count($trainRows),
                'evaluation_rows' => count($evaluationRows),
                'train_range' => [
                    'start' => $trainRows[0]['game_start_at'] ?? $trainRows[0]['game_date'] ?? null,
                    'end' => $trainRows[array_key_last($trainRows)]['game_start_at']
                        ?? $trainRows[array_key_last($trainRows)]['game_date']
                        ?? null,
                ],
                'evaluation_range' => [
                    'start' => $evaluationRows[0]['game_start_at'] ?? $evaluationRows[0]['game_date'] ?? null,
                    'end' => $evaluationRows[array_key_last($evaluationRows)]['game_start_at']
                        ?? $evaluationRows[array_key_last($evaluationRows)]['game_date']
                        ?? null,
                ],
                'model' => $model,
                'metrics' => $metrics,
            ];
        }

        return [
            'summary' => $this->summarize($windows),
            'windows' => $windows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return array<string, float|int>
     */
    private function summarize(array $windows): array
    {
        $windowCount = count($windows);
        $average = function (string $key) use ($windows, $windowCount): float {
            return $windowCount === 0
                ? 0.0
                : array_sum(array_map(fn (array $window): float => (float) data_get($window, $key), $windows)) / $windowCount;
        };

        return [
            'window_count' => $windowCount,
            'avg_baseline_brier' => $average('metrics.baseline_brier'),
            'avg_challenger_brier' => $average('metrics.challenger_brier'),
            'avg_brier_delta' => $average('metrics.brier_delta'),
            'avg_baseline_log_loss' => $average('metrics.baseline_log_loss'),
            'avg_challenger_log_loss' => $average('metrics.challenger_log_loss'),
            'avg_log_loss_delta' => $average('metrics.log_loss_delta'),
            'challenger_better_window_count' => count(array_filter(
                $windows,
                fn (array $window): bool => (float) data_get($window, 'metrics.brier_delta') < 0.0
                    && (float) data_get($window, 'metrics.log_loss_delta') < 0.0,
            )),
        ];
    }
}
