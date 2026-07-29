<?php

namespace App\Console\Commands\NFL;

use App\Services\ML\ModelArtifactRegistry;
use App\Services\NFL\NflPredictionProfileConfigurator;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RegisterHistoricalProfileArtifactCommand extends Command
{
    protected $signature = 'nfl:register-historical-profile-artifact
        {--profile=full-historical : Evaluated NFL historical profile}
        {--dataset=storage/app/ml/nfl_full_historical_training_data.csv : Exact challenger dataset}
        {--report=storage/app/ml/reports/nfl_historical_profile_comparison.json : Rolling-season comparison report}
        {--output=storage/app/ml/models/nfl_full_historical_profile.json : Inference manifest alias}';

    protected $description = 'Register a reproducible NFL historical profile as a shadow challenger artifact';

    public function handle(
        NflPredictionProfileConfigurator $profiles,
        ModelRunRecorder $runs,
        ModelArtifactRegistry $artifacts,
    ): int {
        $profile = (string) $this->option('profile');
        $datasetPath = $this->absolutePath((string) $this->option('dataset'));
        $reportPath = $this->absolutePath((string) $this->option('report'));
        $outputPath = $this->absolutePath((string) $this->option('output'));

        try {
            $profileOverrides = $profiles->overrides($profile);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! File::exists($datasetPath) || ! File::exists($reportPath)) {
            $this->error('The exact challenger dataset and rolling evaluation report are required.');

            return self::FAILURE;
        }

        $report = json_decode((string) File::get($reportPath), true);
        if (! is_array($report) || ($report['report_type'] ?? null) !== 'nfl_historical_profile_rolling_season_comparison') {
            $this->error('The NFL historical profile comparison report is invalid.');

            return self::FAILURE;
        }

        $datasetHash = (string) hash_file('sha256', $datasetPath);
        $reportedDatasetHash = (string) data_get($report, 'challenger.hash', '');
        if ($reportedDatasetHash === '' || ! hash_equals($datasetHash, $reportedDatasetHash)) {
            $this->error('The report challenger hash does not match the selected dataset.');

            return self::FAILURE;
        }

        $summary = (array) ($report['summary'] ?? []);
        if ((int) ($summary['window_count'] ?? 0) < 2) {
            $this->error('The report must contain at least two chronological evaluation windows.');

            return self::FAILURE;
        }

        $artifactId = $artifacts->newId();
        $trainingRun = $profiles->withProfile(
            $profile,
            fn () => $runs->create(
                sport: 'nfl',
                runType: 'profile_validation',
                modelVersion: 'nfl-full-historical-profile-v1',
                featureVersion: 'nfl-pregame-core-v1',
                blendVersion: 'full-historical-shadow-v1',
                parameters: [
                    'profile' => $profile,
                    'dataset_hash' => $datasetHash,
                    'report_hash' => hash_file('sha256', $reportPath),
                    'profile_overrides' => $profileOverrides,
                ],
                metadata: [
                    'market_type' => 'win_probability',
                    'evaluation_report_path' => $reportPath,
                    'evaluation_summary' => $summary,
                ],
            ),
        );

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode([
            'artifact_id' => $artifactId,
            'training_run_id' => $trainingRun->id,
            'config_hash' => $trainingRun->config_hash,
            'code_version' => $trainingRun->code_version,
            'dataset_hash' => $datasetHash,
            'report_hash' => hash_file('sha256', $reportPath),
            'model_type' => 'nfl_full_historical_profile',
            'model_version' => 'nfl-full-historical-profile-v1',
            'feature_version' => 'nfl-pregame-core-v1',
            'blend_version' => 'full-historical-shadow-v1',
            'profile' => $profile,
            'profile_overrides' => $profileOverrides,
            'evaluation_summary' => $summary,
            'registered_at' => now()->toIso8601String(),
            'source' => [
                'dataset_path' => $datasetPath,
                'evaluation_report_path' => $reportPath,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $artifact = $artifacts->register(
            id: $artifactId,
            trainingRun: $trainingRun,
            marketType: 'win_probability',
            modelType: 'nfl_full_historical_profile',
            modelVersion: 'nfl-full-historical-profile-v1',
            featureVersion: 'nfl-pregame-core-v1',
            datasetHash: $datasetHash,
            artifactPath: $outputPath,
            metrics: [
                'rolling_season' => $summary,
            ],
            datasetPath: $datasetPath,
            evaluationReportPath: $reportPath,
        );

        $this->info('NFL historical profile challenger registered.');
        $this->line('Model run: '.$trainingRun->id);
        $this->line('Config hash: '.$trainingRun->config_hash);
        $this->line('Artifact id: '.$artifact->id);
        $this->line('Artifact hash: '.$artifact->artifact_hash);
        $this->line('Held-out seasons: '.(int) ($summary['window_count'] ?? 0));
        $this->line('Better seasons: '.(int) ($summary['challenger_better_window_count'] ?? 0));

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
