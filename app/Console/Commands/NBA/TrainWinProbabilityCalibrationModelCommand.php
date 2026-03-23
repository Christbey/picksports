<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\WinProbabilityCalibrationTrainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainWinProbabilityCalibrationModelCommand extends Command
{
    protected $signature = 'nba:train-win-probability-calibration-model
        {--input-dir=storage/app/ml/splits : Directory containing split CSV files}
        {--output=storage/app/ml/models/nba_win_probability_calibration_model.json : Output artifact path}
        {--learning-rate=0.01 : Gradient descent learning rate}
        {--iterations=3000 : Training iterations}';

    protected $description = 'Train an NBA win-probability calibration challenger model from snapshot split CSV files';

    public function handle(WinProbabilityCalibrationTrainer $trainer): int
    {
        $inputDir = $this->absolutePath((string) $this->option('input-dir'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $learningRate = max(0.000001, (float) $this->option('learning-rate'));
        $iterations = max(1, (int) $this->option('iterations'));

        $trainRows = $this->readCsv($inputDir.'/nba_snapshot_train.csv');
        $validationRows = $this->readCsv($inputDir.'/nba_snapshot_validation.csv');
        $testRows = $this->readCsv($inputDir.'/nba_snapshot_test.csv');

        if ($trainRows === [] || $validationRows === []) {
            $this->error('Train or validation rows are missing. Run nba:split-snapshot-dataset first.');

            return self::FAILURE;
        }

        $model = $trainer->train($trainRows, $learningRate, $iterations);
        $validationMetrics = $trainer->evaluate($validationRows, $model);
        $testMetrics = $trainer->evaluate($testRows, $model);

        File::ensureDirectoryExists(dirname($outputPath));

        File::put($outputPath, json_encode([
            'model_type' => 'nba_win_probability_platt_calibration',
            'trained_at' => now()->toIso8601String(),
            'alpha' => $model['alpha'],
            'beta' => $model['beta'],
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
            'metrics' => [
                'validation' => $validationMetrics,
                'test' => $testMetrics,
            ],
            'source' => [
                'input_dir' => $inputDir,
                'train_file' => 'nba_snapshot_train.csv',
                'validation_file' => 'nba_snapshot_validation.csv',
                'test_file' => 'nba_snapshot_test.csv',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('NBA win-probability calibration challenger model trained.');
        $this->line('Artifact: '.$outputPath);
        $this->newLine();
        $this->table(
            ['Split', 'Rows', 'Baseline Brier', 'Challenger Brier', 'Brier Delta', 'Baseline LogLoss', 'Challenger LogLoss'],
            [
                [
                    'Validation',
                    (string) $validationMetrics['count'],
                    number_format($validationMetrics['baseline_brier'], 4),
                    number_format($validationMetrics['challenger_brier'], 4),
                    number_format($validationMetrics['brier_delta'], 4),
                    number_format($validationMetrics['baseline_log_loss'], 4),
                    number_format($validationMetrics['challenger_log_loss'], 4),
                ],
                [
                    'Test',
                    (string) $testMetrics['count'],
                    number_format($testMetrics['baseline_brier'], 4),
                    number_format($testMetrics['challenger_brier'], 4),
                    number_format($testMetrics['brier_delta'], 4),
                    number_format($testMetrics['baseline_log_loss'], 4),
                    number_format($testMetrics['challenger_log_loss'], 4),
                ],
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
}
