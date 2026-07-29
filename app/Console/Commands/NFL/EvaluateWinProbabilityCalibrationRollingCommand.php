<?php

namespace App\Console\Commands\NFL;

use App\Models\ModelArtifact;
use App\Services\ML\CsvDataset;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\RollingWinProbabilityEvaluator;
use App\Services\ML\WinProbabilityCalibrationTrainer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EvaluateWinProbabilityCalibrationRollingCommand extends Command
{
    protected $signature = 'nfl:evaluate-win-probability-calibration-rolling
        {--input=storage/app/ml/nfl_training_data.csv : Source trusted dataset}
        {--output=storage/app/ml/reports/nfl_win_probability_calibration_rolling.json : Report path}
        {--artifact-id= : Attach report to a registered artifact}
        {--row-windows : Use fixed-size row windows instead of whole held-out seasons}
        {--min-train-size=1000 : Minimum chronological training rows}
        {--test-window-size=272 : Evaluation rows per window}
        {--step-size=272 : Rows advanced per window}
        {--learning-rate=0.01 : Gradient descent learning rate}
        {--iterations=3000 : Training iterations}';

    protected $description = 'Run expanding-window NFL calibration evaluation against the baseline';

    public function handle(
        CsvDataset $csv,
        RollingWinProbabilityEvaluator $rolling,
        WinProbabilityCalibrationTrainer $trainer,
        ModelArtifactRegistry $artifacts,
    ): int {
        $inputPath = $this->absolutePath((string) $this->option('input'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $rows = $csv->read($inputPath);
        $minTrainSize = max(1, (int) $this->option('min-train-size'));

        if (count($rows) <= $minTrainSize) {
            $this->error('Not enough rows for the requested NFL rolling evaluation.');

            return self::FAILURE;
        }

        $config = [
            'input' => $inputPath,
            'input_hash' => hash_file('sha256', $inputPath),
            'window_mode' => (bool) $this->option('row-windows') ? 'fixed_rows' : 'season',
            'min_train_size' => $minTrainSize,
            'test_window_size' => max(1, (int) $this->option('test-window-size')),
            'step_size' => max(1, (int) $this->option('step-size')),
            'learning_rate' => max(0.000001, (float) $this->option('learning-rate')),
            'iterations' => max(1, (int) $this->option('iterations')),
        ];
        $evaluation = (bool) $this->option('row-windows')
            ? $rolling->evaluate(
                rows: $rows,
                trainer: $trainer,
                minTrainSize: $config['min_train_size'],
                testWindowSize: $config['test_window_size'],
                stepSize: $config['step_size'],
                learningRate: $config['learning_rate'],
                iterations: $config['iterations'],
            )
            : $rolling->evaluateBySeason(
                rows: $rows,
                trainer: $trainer,
                minTrainSize: $config['min_train_size'],
                learningRate: $config['learning_rate'],
                iterations: $config['iterations'],
            );

        if ($evaluation['windows'] === []) {
            $this->error('No rolling windows were generated.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode([
            'report_type' => 'nfl_win_probability_calibration_rolling_evaluation',
            'generated_at' => now()->toIso8601String(),
            'config' => $config,
            ...$evaluation,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $reportHash = hash_file('sha256', $outputPath);

        if ($this->option('artifact-id')) {
            $artifact = ModelArtifact::query()->findOrFail((string) $this->option('artifact-id'));
            $artifacts->attachEvaluationReport($artifact, $outputPath);
        }

        $summary = $evaluation['summary'];
        $this->info('NFL rolling-season calibration evaluation completed.');
        $this->line("Report: {$outputPath}");
        $this->line("Report SHA-256: {$reportHash}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Windows', (string) $summary['window_count']],
                ['Avg baseline Brier', number_format($summary['avg_baseline_brier'], 4)],
                ['Avg challenger Brier', number_format($summary['avg_challenger_brier'], 4)],
                ['Avg Brier delta', number_format($summary['avg_brier_delta'], 4)],
                ['Avg LogLoss delta', number_format($summary['avg_log_loss_delta'], 4)],
                ['Challenger better windows', (string) $summary['challenger_better_window_count']],
            ],
        );

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
