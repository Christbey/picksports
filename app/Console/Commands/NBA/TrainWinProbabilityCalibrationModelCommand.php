<?php

namespace App\Console\Commands\NBA;

use App\Services\ML\CsvDataset;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\NBA\WinProbabilityCalibrationTrainer;
use App\Services\Predictions\ModelRunRecorder;
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

    public function handle(
        WinProbabilityCalibrationTrainer $trainer,
        CsvDataset $csv,
        ModelRunRecorder $runRecorder,
        ModelArtifactRegistry $artifacts,
    ): int {
        $inputDir = $this->absolutePath((string) $this->option('input-dir'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $learningRate = max(0.000001, (float) $this->option('learning-rate'));
        $iterations = max(1, (int) $this->option('iterations'));

        $paths = [
            $inputDir.'/nba_snapshot_train.csv',
            $inputDir.'/nba_snapshot_validation.csv',
            $inputDir.'/nba_snapshot_test.csv',
        ];
        $trainRows = $csv->read($paths[0]);
        $validationRows = $csv->read($paths[1]);
        $testRows = $csv->read($paths[2]);

        if ($trainRows === [] || $validationRows === []) {
            $this->error('Train or validation rows are missing. Run nba:split-snapshot-dataset first.');

            return self::FAILURE;
        }

        $model = $trainer->train($trainRows, $learningRate, $iterations);
        $validationMetrics = $trainer->evaluate($validationRows, $model);
        $testMetrics = $trainer->evaluate($testRows, $model);
        $datasetHash = $csv->hashFiles($paths);
        $artifactId = $artifacts->newId();
        $trainingRun = $runRecorder->create(
            sport: 'nba',
            runType: 'training',
            modelVersion: 'nba-win-probability-platt-v1',
            featureVersion: 'trusted-snapshot-v1',
            blendVersion: 'challenger-shadow-v1',
            parameters: [
                'learning_rate' => $learningRate,
                'iterations' => $iterations,
                'dataset_hash' => $datasetHash,
            ],
            metadata: ['market_type' => 'win_probability'],
        );

        File::ensureDirectoryExists(dirname($outputPath));

        File::put($outputPath, json_encode([
            'artifact_id' => $artifactId,
            'training_run_id' => $trainingRun->id,
            'config_hash' => $trainingRun->config_hash,
            'code_version' => $trainingRun->code_version,
            'dataset_hash' => $datasetHash,
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
        $artifact = $artifacts->register(
            id: $artifactId,
            trainingRun: $trainingRun,
            marketType: 'win_probability',
            modelType: 'nba_win_probability_platt_calibration',
            modelVersion: 'nba-win-probability-platt-v1',
            featureVersion: 'trusted-snapshot-v1',
            datasetHash: $datasetHash,
            artifactPath: $outputPath,
            metrics: [
                'validation' => $validationMetrics,
                'test' => $testMetrics,
            ],
        );

        $this->info('NBA win-probability calibration challenger model trained.');
        $this->line('Model run: '.$trainingRun->id);
        $this->line('Artifact id: '.$artifact->id);
        $this->line('Config hash: '.$trainingRun->config_hash);
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
}
