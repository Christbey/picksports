<?php

namespace App\Console\Commands\NFL;

use App\Services\ML\CsvDataset;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\WinProbabilityCalibrationTrainer;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainWinProbabilityCalibrationModelCommand extends Command
{
    protected $signature = 'nfl:train-win-probability-calibration-model
        {--input-dir=storage/app/ml/nfl-splits : Directory containing split CSV files}
        {--output=storage/app/ml/models/nfl_win_probability_calibration_model.json : Artifact path}
        {--learning-rate=0.01 : Gradient descent learning rate}
        {--iterations=3000 : Training iterations}';

    protected $description = 'Train and register an NFL win-probability calibration challenger';

    public function handle(
        WinProbabilityCalibrationTrainer $trainer,
        CsvDataset $csv,
        ModelRunRecorder $runRecorder,
        ModelArtifactRegistry $artifacts,
    ): int {
        $inputDir = $this->absolutePath((string) $this->option('input-dir'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $paths = [
            $inputDir.'/nfl_snapshot_train.csv',
            $inputDir.'/nfl_snapshot_validation.csv',
            $inputDir.'/nfl_snapshot_test.csv',
        ];
        $trainRows = $csv->read($paths[0]);
        $validationRows = $csv->read($paths[1]);
        $testRows = $csv->read($paths[2]);

        if ($trainRows === [] || $validationRows === []) {
            $this->error('Train or validation rows are missing. Run nfl:split-snapshot-dataset first.');

            return self::FAILURE;
        }

        $learningRate = max(0.000001, (float) $this->option('learning-rate'));
        $iterations = max(1, (int) $this->option('iterations'));
        $model = $trainer->train($trainRows, $learningRate, $iterations);
        $metrics = [
            'validation' => $trainer->evaluate($validationRows, $model),
            'test' => $trainer->evaluate($testRows, $model),
        ];
        $datasetHash = $csv->hashFiles($paths);
        $artifactId = $artifacts->newId();
        $trainingRun = $runRecorder->create(
            sport: 'nfl',
            runType: 'training',
            modelVersion: 'nfl-win-probability-platt-v1',
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
            'model_type' => 'nfl_win_probability_platt_calibration',
            'trained_at' => now()->toIso8601String(),
            'alpha' => $model['alpha'],
            'beta' => $model['beta'],
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
            'metrics' => $metrics,
            'source' => [
                'input_dir' => $inputDir,
                'train_file' => basename($paths[0]),
                'validation_file' => basename($paths[1]),
                'test_file' => basename($paths[2]),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $artifact = $artifacts->register(
            id: $artifactId,
            trainingRun: $trainingRun,
            marketType: 'win_probability',
            modelType: 'nfl_win_probability_platt_calibration',
            modelVersion: 'nfl-win-probability-platt-v1',
            featureVersion: 'trusted-snapshot-v1',
            datasetHash: $datasetHash,
            artifactPath: $outputPath,
            metrics: $metrics,
        );

        $this->info('NFL win-probability challenger trained and registered.');
        $this->line('Model run: '.$trainingRun->id);
        $this->line('Config hash: '.$trainingRun->config_hash);
        $this->line('Artifact id: '.$artifact->id);
        $this->line('Artifact hash: '.$artifact->artifact_hash);
        $this->table(
            ['Split', 'Rows', 'Baseline Brier', 'Challenger Brier', 'Brier Delta'],
            collect($metrics)->map(fn (array $values, string $split): array => [
                ucfirst($split),
                (string) $values['count'],
                number_format($values['baseline_brier'], 4),
                number_format($values['challenger_brier'], 4),
                number_format($values['brier_delta'], 4),
            ])->values()->all(),
        );

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
