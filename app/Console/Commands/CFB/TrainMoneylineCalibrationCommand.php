<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbMoneylineCalibrationDataset;
use App\Services\ML\CsvDataset;
use App\Services\ML\EvaluationReportNormalizer;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\WinProbabilityCalibrationTrainer;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainMoneylineCalibrationCommand extends Command
{
    protected $signature = 'cfb:train-moneyline-calibration
        {--from-season=2022 : First historical season}
        {--to-season=2025 : Last historical season (held out from final training)}
        {--min-week=0 : First week to include}
        {--max-week=4 : Last week to include}
        {--dataset=storage/app/ml/datasets/cfb_moneyline_calibration.csv : Reconstructed dataset path}
        {--output=storage/app/ml/models/cfb_moneyline_calibration_model.json : Model artifact path}
        {--report=storage/app/ml/reports/cfb_moneyline_calibration_rolling.json : Rolling evaluation report path}
        {--learning-rate=0.01 : Gradient descent learning rate}
        {--iterations=3000 : Training iterations}
        {--min-rows=300 : Minimum reconstructable rows required}';

    protected $description = 'Reconstruct, train, and register a season-held-out CFB moneyline calibration challenger';

    public function handle(
        CfbMoneylineCalibrationDataset $dataset,
        CsvDataset $csv,
        WinProbabilityCalibrationTrainer $trainer,
        ModelRunRecorder $runRecorder,
        ModelArtifactRegistry $artifacts,
    ): int {
        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) $this->option('to-season');
        $minWeek = (int) $this->option('min-week');
        $maxWeek = (int) $this->option('max-week');
        $minimumRows = max(1, (int) $this->option('min-rows'));

        if ($fromSeason >= $toSeason || $minWeek > $maxWeek) {
            $this->error('The season and week ranges are invalid.');

            return self::FAILURE;
        }

        $rows = $dataset->rows($fromSeason, $toSeason, $minWeek, $maxWeek);
        $seasons = collect($rows)->pluck('season')->map(fn ($season): int => (int) $season)->unique()->sort()->values();

        if (count($rows) < $minimumRows || $seasons->count() < 4) {
            $this->error(sprintf(
                'Calibration requires at least %d rows across four seasons; found %d rows across %d seasons.',
                $minimumRows,
                count($rows),
                $seasons->count(),
            ));

            return self::FAILURE;
        }

        $datasetPath = $this->absolutePath((string) $this->option('dataset'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $reportPath = $this->absolutePath((string) $this->option('report'));
        $learningRate = max(0.000001, (float) $this->option('learning-rate'));
        $iterations = max(1, (int) $this->option('iterations'));
        $testSeason = (int) $seasons->last();
        $validationSeason = (int) $seasons->slice(-2, 1)->first();
        $trainingRows = $this->beforeSeason($rows, $validationSeason);
        $validationRows = $this->forSeason($rows, $validationSeason);
        $finalTrainingRows = $this->beforeSeason($rows, $testSeason);
        $testRows = $this->forSeason($rows, $testSeason);

        if ($trainingRows === [] || $validationRows === [] || $testRows === []) {
            $this->error('A season-separated train, validation, and test split could not be built.');

            return self::FAILURE;
        }

        $validationModel = $trainer->train($trainingRows, $learningRate, $iterations);
        $finalModel = $trainer->train($finalTrainingRows, $learningRate, $iterations);
        $metrics = [
            'validation' => $trainer->evaluate($validationRows, $validationModel),
            'test' => $trainer->evaluate($testRows, $finalModel),
        ];
        $windows = $this->rollingWindows($rows, $seasons->all(), $trainer, $learningRate, $iterations);
        $report = $this->report($windows, $fromSeason, $toSeason, $minWeek, $maxWeek);

        $csv->write($datasetPath, $rows);
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $datasetHash = hash_file('sha256', $datasetPath);

        if (! is_string($datasetHash)) {
            throw new \RuntimeException('Unable to hash the CFB calibration dataset.');
        }

        $artifactId = $artifacts->newId();
        $trainingRun = $runRecorder->create(
            sport: 'cfb',
            runType: 'training',
            modelVersion: 'cfb-moneyline-platt-v1',
            featureVersion: CfbMoneylineCalibrationDataset::FEATURE_VERSION,
            blendVersion: 'challenger-shadow-v1',
            parameters: [
                'from_season' => $fromSeason,
                'to_season' => $toSeason,
                'min_week' => $minWeek,
                'max_week' => $maxWeek,
                'learning_rate' => $learningRate,
                'iterations' => $iterations,
                'dataset_hash' => $datasetHash,
                'validation_season' => $validationSeason,
                'test_season' => $testSeason,
            ],
            metadata: [
                'market_type' => 'win_probability',
                'point_in_time_basis' => 'verified_reconstruction',
                'activation_policy' => 'challenger_only_until_offline_and_live_shadow_gates_pass',
            ],
        );

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode([
            'artifact_id' => $artifactId,
            'training_run_id' => $trainingRun->id,
            'config_hash' => $trainingRun->config_hash,
            'code_version' => $trainingRun->code_version,
            'dataset_hash' => $datasetHash,
            'model_type' => 'cfb_moneyline_platt_calibration',
            'model_version' => 'cfb-moneyline-platt-v1',
            'feature_version' => CfbMoneylineCalibrationDataset::FEATURE_VERSION,
            'trained_at' => now()->toIso8601String(),
            'alpha' => $finalModel['alpha'],
            'beta' => $finalModel['beta'],
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
            'training_seasons' => $seasons->filter(fn (int $season): bool => $season < $testSeason)->values()->all(),
            'validation_season' => $validationSeason,
            'test_season' => $testSeason,
            'metrics' => $metrics,
            'activation_status' => 'challenger',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $artifact = $artifacts->register(
            id: $artifactId,
            trainingRun: $trainingRun,
            marketType: 'win_probability',
            modelType: 'cfb_moneyline_platt_calibration',
            modelVersion: 'cfb-moneyline-platt-v1',
            featureVersion: CfbMoneylineCalibrationDataset::FEATURE_VERSION,
            datasetHash: $datasetHash,
            artifactPath: $outputPath,
            metrics: $metrics,
            datasetPath: $datasetPath,
            evaluationReportPath: $reportPath,
        );

        $this->info('CFB moneyline calibration challenger trained and registered.');
        $this->line('Rows: '.count($rows)." across seasons {$seasons->first()}-{$seasons->last()}");
        $this->line("Validation season: {$validationSeason}; held-out test season: {$testSeason}");
        $this->line('Artifact id: '.$artifact->id);
        $this->line('Status: '.$artifact->status.' (not active)');
        $this->table(
            ['Split', 'Rows', 'Baseline Brier', 'Calibrated Brier', 'Improvement', 'Log-loss improvement'],
            collect($metrics)->map(fn (array $values, string $split): array => [
                ucfirst($split),
                (string) $values['count'],
                number_format($values['baseline_brier'], 4),
                number_format($values['challenger_brier'], 4),
                number_format(-$values['brier_delta'], 4),
                number_format(-$values['log_loss_delta'], 4),
            ])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, string>>
     */
    private function beforeSeason(array $rows, int $season): array
    {
        return $this->stringRows(array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) $row['season'] < $season,
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, string>>
     */
    private function forSeason(array $rows, int $season): array
    {
        return $this->stringRows(array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) $row['season'] === $season,
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $seasons
     * @return list<array<string, mixed>>
     */
    private function rollingWindows(
        array $rows,
        array $seasons,
        WinProbabilityCalibrationTrainer $trainer,
        float $learningRate,
        int $iterations,
    ): array {
        $windows = [];

        foreach (array_slice($seasons, 1) as $evaluationSeason) {
            $trainingRows = $this->beforeSeason($rows, $evaluationSeason);
            $evaluationRows = $this->forSeason($rows, $evaluationSeason);
            if ($trainingRows === [] || $evaluationRows === []) {
                continue;
            }

            $model = $trainer->train($trainingRows, $learningRate, $iterations);
            $metrics = $trainer->evaluate($evaluationRows, $model);
            $windows[] = [
                'evaluation_season' => $evaluationSeason,
                'training_seasons' => array_values(array_filter($seasons, fn (int $season): bool => $season < $evaluationSeason)),
                'training_rows' => count($trainingRows),
                'evaluation_rows' => count($evaluationRows),
                ...$metrics,
            ];
        }

        return $windows;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return array<string, mixed>
     */
    private function report(array $windows, int $fromSeason, int $toSeason, int $minWeek, int $maxWeek): array
    {
        $average = function (string $key) use ($windows): ?float {
            if ($windows === []) {
                return null;
            }

            return array_sum(array_column($windows, $key)) / count($windows);
        };

        return [
            'sport' => 'cfb',
            'market_type' => 'win_probability',
            'evaluation_type' => 'expanding_season_walk_forward',
            'delta_convention' => EvaluationReportNormalizer::CHALLENGER_MINUS_BASELINE,
            'generated_at' => now()->toIso8601String(),
            'dataset' => [
                'from_season' => $fromSeason,
                'to_season' => $toSeason,
                'min_week' => $minWeek,
                'max_week' => $maxWeek,
                'feature_version' => CfbMoneylineCalibrationDataset::FEATURE_VERSION,
            ],
            'summary' => [
                'window_count' => count($windows),
                'challenger_better_window_count' => count(array_filter(
                    $windows,
                    fn (array $window): bool => $window['brier_delta'] < 0 && $window['log_loss_delta'] < 0,
                )),
                'avg_brier_delta' => $average('brier_delta'),
                'avg_log_loss_delta' => $average('log_loss_delta'),
                'delta_convention' => EvaluationReportNormalizer::CHALLENGER_MINUS_BASELINE,
            ],
            'windows' => $windows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, string>>
     */
    private function stringRows(array $rows): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (mixed $value): string => (string) $value, $row),
            $rows,
        );
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
