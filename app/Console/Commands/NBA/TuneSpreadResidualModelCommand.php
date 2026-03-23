<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\SpreadResidualModelTrainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TuneSpreadResidualModelCommand extends Command
{
    protected $signature = 'nba:tune-spread-residual-model
        {--input-dir=storage/app/ml/splits : Directory containing split CSV files}
        {--output=storage/app/ml/models/nba_spread_residual_model_tuned.json : Output artifact path for the best model}
        {--ridge-values=0,0.01,0.1,1,10,100 : Comma-separated ridge values to evaluate}';

    protected $description = 'Tune the NBA challenger spread residual model by sweeping ridge values';

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

        $trainRows = $this->readCsv($inputDir.'/nba_snapshot_train.csv');
        $validationRows = $this->readCsv($inputDir.'/nba_snapshot_validation.csv');
        $testRows = $this->readCsv($inputDir.'/nba_snapshot_test.csv');

        if ($trainRows === [] || $validationRows === []) {
            $this->error('Train or validation rows are missing. Run nba:split-snapshot-dataset first.');

            return self::FAILURE;
        }

        $ridgeValues = $this->parseRidgeValues((string) $this->option('ridge-values'));
        if ($ridgeValues === []) {
            $this->error('No valid ridge values were provided.');

            return self::FAILURE;
        }

        $results = [];
        $best = null;

        foreach ($ridgeValues as $ridge) {
            $model = $trainer->train($trainRows, $this->featureColumns, $ridge);
            $validationMetrics = $trainer->evaluate($validationRows, $model);
            $testMetrics = $trainer->evaluate($testRows, $model);

            $result = [
                'ridge' => $ridge,
                'model' => $model,
                'validation' => $validationMetrics,
                'test' => $testMetrics,
            ];

            $results[] = $result;

            if ($best === null || $validationMetrics['challenger_mae'] < $best['validation']['challenger_mae']) {
                $best = $result;
            }
        }

        usort($results, function (array $left, array $right): int {
            return $left['validation']['challenger_mae'] <=> $right['validation']['challenger_mae'];
        });

        File::ensureDirectoryExists(dirname($outputPath));

        File::put($outputPath, json_encode([
            'model_type' => 'nba_spread_residual_ridge',
            'trained_at' => now()->toIso8601String(),
            'selection_metric' => 'validation_challenger_mae',
            'feature_columns' => $this->featureColumns,
            'best_ridge_lambda' => $best['ridge'],
            'intercept' => $best['model']['intercept'],
            'coefficients' => $best['model']['coefficients'],
            'metrics' => [
                'validation' => $best['validation'],
                'test' => $best['test'],
            ],
            'sweep_results' => array_map(fn (array $result): array => [
                'ridge_lambda' => $result['ridge'],
                'validation' => $result['validation'],
                'test' => $result['test'],
            ], $results),
            'source' => [
                'input_dir' => $inputDir,
                'train_file' => 'nba_snapshot_train.csv',
                'validation_file' => 'nba_snapshot_validation.csv',
                'test_file' => 'nba_snapshot_test.csv',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('NBA spread residual tuning completed.');
        $this->line('Best artifact: '.$outputPath);
        $this->line('Best ridge: '.(string) $best['ridge']);
        $this->newLine();
        $this->table(
            ['Ridge', 'Validation Rows', 'Validation MAE', 'Test MAE', 'Validation Delta', 'Test Delta'],
            array_map(fn (array $result): array => [
                $this->formatFloat($result['ridge']),
                (string) $result['validation']['count'],
                number_format($result['validation']['challenger_mae'], 3),
                number_format($result['test']['challenger_mae'], 3),
                number_format($result['validation']['mae_delta'], 3),
                number_format($result['test']['mae_delta'], 3),
            ], $results)
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
     * @return list<float>
     */
    private function parseRidgeValues(string $ridgeValues): array
    {
        $values = array_filter(array_map('trim', explode(',', $ridgeValues)), fn (string $value): bool => $value !== '');

        return array_values(array_unique(array_map(function (string $value): float {
            return max(0.0, (float) $value);
        }, $values)));
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

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
