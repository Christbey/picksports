<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\SpreadResidualModelTrainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainSpreadResidualModelCommand extends Command
{
    protected $signature = 'nba:train-spread-residual-model
        {--input-dir=storage/app/ml/splits : Directory containing split CSV files}
        {--output=storage/app/ml/models/nba_spread_residual_model.json : Output artifact path}
        {--ridge=1.0 : Ridge regularization strength}';

    protected $description = 'Train a first NBA challenger spread residual model from snapshot split CSV files';

    /**
     * @var list<string>
     */
    private array $featureColumns = [
        'feature_elo_diff',
        'feature_recent_form_diff',
        'feature_rest_day_diff',
        'feature_injury_spread_adj',
        'feature_market_home_spread',
        'feature_model_predicted_spread',
        'feature_confidence_score',
    ];

    public function handle(SpreadResidualModelTrainer $trainer): int
    {
        $inputDir = $this->absolutePath((string) $this->option('input-dir'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $ridge = max(0.0, (float) $this->option('ridge'));

        $trainRows = $this->readCsv($inputDir.'/nba_snapshot_train.csv');
        $validationRows = $this->readCsv($inputDir.'/nba_snapshot_validation.csv');
        $testRows = $this->readCsv($inputDir.'/nba_snapshot_test.csv');

        if ($trainRows === []) {
            $this->error('No train rows found. Run nba:split-snapshot-dataset first.');

            return self::FAILURE;
        }

        $model = $trainer->train($trainRows, $this->featureColumns, $ridge);
        $validationMetrics = $trainer->evaluate($validationRows, $model);
        $testMetrics = $trainer->evaluate($testRows, $model);

        File::ensureDirectoryExists(dirname($outputPath));

        File::put($outputPath, json_encode([
            'model_type' => 'nba_spread_residual_ridge',
            'trained_at' => now()->toIso8601String(),
            'ridge_lambda' => $ridge,
            'feature_columns' => $this->featureColumns,
            'intercept' => $model['intercept'],
            'coefficients' => $model['coefficients'],
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

        $this->info('NBA spread residual challenger model trained.');
        $this->line('Artifact: '.$outputPath);
        $this->newLine();
        $this->table(
            ['Split', 'Rows', 'Baseline MAE', 'Challenger MAE', 'Delta'],
            [
                [
                    'Validation',
                    (string) $validationMetrics['count'],
                    number_format($validationMetrics['baseline_mae'], 3),
                    number_format($validationMetrics['challenger_mae'], 3),
                    number_format($validationMetrics['mae_delta'], 3),
                ],
                [
                    'Test',
                    (string) $testMetrics['count'],
                    number_format($testMetrics['baseline_mae'], 3),
                    number_format($testMetrics['challenger_mae'], 3),
                    number_format($testMetrics['mae_delta'], 3),
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
