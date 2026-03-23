<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\WinProbabilityCalibrationTrainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EvaluateWinProbabilityCalibrationRollingCommand extends Command
{
    protected $signature = 'nba:evaluate-win-probability-calibration-rolling
        {--input=storage/app/ml/nba_snapshot_dataset.csv : Source dataset CSV}
        {--output=storage/app/ml/reports/nba_win_probability_calibration_rolling.json : Output report path}
        {--min-train-size=120 : Minimum number of chronological rows before first evaluation window}
        {--test-window-size=24 : Number of rows per evaluation window}
        {--step-size=24 : Number of rows to advance between windows}
        {--learning-rate=0.01 : Gradient descent learning rate}
        {--iterations=3000 : Training iterations}';

    protected $description = 'Run expanding-window evaluation for the NBA win-probability calibration challenger model';

    public function handle(WinProbabilityCalibrationTrainer $trainer): int
    {
        $inputPath = $this->absolutePath((string) $this->option('input'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $minTrainSize = max(1, (int) $this->option('min-train-size'));
        $testWindowSize = max(1, (int) $this->option('test-window-size'));
        $stepSize = max(1, (int) $this->option('step-size'));
        $learningRate = max(0.000001, (float) $this->option('learning-rate'));
        $iterations = max(1, (int) $this->option('iterations'));

        $rows = $this->readCsv($inputPath);
        if ($rows === []) {
            $this->error('No dataset rows found. Run nba:export-snapshot-dataset first.');

            return self::FAILURE;
        }

        usort($rows, fn (array $left, array $right): int => [$left['game_date'] ?? '', $left['prediction_id'] ?? ''] <=> [$right['game_date'] ?? '', $right['prediction_id'] ?? '']);

        if (count($rows) <= $minTrainSize) {
            $this->error('Not enough rows for the requested rolling evaluation window sizes.');

            return self::FAILURE;
        }

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
                    'start' => $trainRows[0]['game_date'] ?? null,
                    'end' => $trainRows[array_key_last($trainRows)]['game_date'] ?? null,
                ],
                'evaluation_range' => [
                    'start' => $evaluationRows[0]['game_date'] ?? null,
                    'end' => $evaluationRows[array_key_last($evaluationRows)]['game_date'] ?? null,
                ],
                'model' => [
                    'alpha' => $model['alpha'],
                    'beta' => $model['beta'],
                ],
                'metrics' => $metrics,
            ];
        }

        if ($windows === []) {
            $this->error('No rolling windows were generated with the requested settings.');

            return self::FAILURE;
        }

        $summary = $this->summarizeWindows($windows);

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode([
            'report_type' => 'nba_win_probability_calibration_rolling_evaluation',
            'generated_at' => now()->toIso8601String(),
            'config' => [
                'input' => $inputPath,
                'min_train_size' => $minTrainSize,
                'test_window_size' => $testWindowSize,
                'step_size' => $stepSize,
                'learning_rate' => $learningRate,
                'iterations' => $iterations,
            ],
            'summary' => $summary,
            'windows' => $windows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('NBA rolling calibration evaluation completed.');
        $this->line('Report: '.$outputPath);
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Windows', (string) $summary['window_count']],
                ['Avg baseline Brier', number_format($summary['avg_baseline_brier'], 4)],
                ['Avg challenger Brier', number_format($summary['avg_challenger_brier'], 4)],
                ['Avg Brier delta', number_format($summary['avg_brier_delta'], 4)],
                ['Avg baseline LogLoss', number_format($summary['avg_baseline_log_loss'], 4)],
                ['Avg challenger LogLoss', number_format($summary['avg_challenger_log_loss'], 4)],
                ['Avg LogLoss delta', number_format($summary['avg_log_loss_delta'], 4)],
                ['Challenger better windows', (string) $summary['challenger_better_window_count']],
            ]
        );

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = array_combine($header, array_pad($row, count($header), '')) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return array<string, float|int>
     */
    private function summarizeWindows(array $windows): array
    {
        $windowCount = count($windows);

        $avg = function (string $key) use ($windows, $windowCount): float {
            $sum = 0.0;
            foreach ($windows as $window) {
                $sum += (float) data_get($window, $key, 0.0);
            }

            return $windowCount > 0 ? $sum / $windowCount : 0.0;
        };

        return [
            'window_count' => $windowCount,
            'avg_baseline_brier' => $avg('metrics.baseline_brier'),
            'avg_challenger_brier' => $avg('metrics.challenger_brier'),
            'avg_brier_delta' => $avg('metrics.brier_delta'),
            'avg_baseline_log_loss' => $avg('metrics.baseline_log_loss'),
            'avg_challenger_log_loss' => $avg('metrics.challenger_log_loss'),
            'avg_log_loss_delta' => $avg('metrics.log_loss_delta'),
            'challenger_better_window_count' => count(array_filter($windows, fn (array $window): bool => (float) data_get($window, 'metrics.brier_delta', 0.0) < 0.0)),
        ];
    }
}
