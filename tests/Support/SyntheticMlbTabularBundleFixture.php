<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Synthetic opaque model bytes, archived/verified but never deserialized or trained. */
final class SyntheticMlbTabularBundleFixture
{
    public static function create(string $runDirectory, string $datasetPath, ?string $modelRunId = null, ?string $artifactId = null): array
    {
        File::ensureDirectoryExists($runDirectory.'/models');
        File::ensureDirectoryExists($runDirectory.'/calibrators');

        $files = [
            'calibrators/logistic_regression_isotonic.json' => 'logistic-isotonic',
            'calibrators/logistic_regression_platt.json' => 'logistic-platt',
            'calibrators/xgboost_isotonic.json' => 'xgboost-isotonic',
            'calibrators/xgboost_platt.json' => 'xgboost-platt',
            'feature_schema.json' => '{"schema_version":"mlb-pregame-ml-v1"}',
            'models/logistic_classifier.json' => 'logistic-model',
            'models/xgboost_classifier.json' => 'xgboost-classifier',
            'models/xgboost_home_margin.json' => 'xgboost-margin',
            'models/xgboost_total_points.json' => 'xgboost-total',
            'prediction_example.json' => "{}\n",
            'preprocessor.json' => 'preprocessor',
        ];
        $modelRunId ??= (string) Str::uuid();
        $artifactId ??= (string) Str::uuid();
        $datasetHash = hash_file('sha256', $datasetPath);
        $featureSchemaHash = hash('sha256', 'mlb-feature-schema');
        $configHash = hash('sha256', 'mlb-training-config');
        $evaluation = [
            'report_type' => 'mlb_tabular_walk_forward_evaluation',
            'model_type' => 'mlb_tabular_bundle',
            'model_run_id' => $modelRunId,
            'artifact_id' => $artifactId,
            'dataset' => ['sha256' => $datasetHash],
            'feature_schema' => ['sha256' => $featureSchemaHash],
            'final_holdout' => ['test_start_at' => '2026-07-01T00:00:00+00:00'],
            'rolling_weekly' => ['summary' => ['window_count' => 4]],
            'promotion_summary' => ['recommendation' => 'shadow_only'],
        ];
        $files['evaluation.json'] = json_encode(
            $evaluation,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        foreach ($files as $relativePath => $contents) {
            File::put($runDirectory.'/'.$relativePath, $contents);
        }

        $inventory = [];
        foreach (array_keys($files) as $relativePath) {
            $path = $runDirectory.'/'.$relativePath;
            $inventory[$relativePath] = [
                'sha256' => hash_file('sha256', $path),
                'bytes' => filesize($path),
            ];
        }
        ksort($inventory);

        $manifest = [
            'manifest_version' => 1,
            'execution_format' => 'picksports-tabular-numeric-v1',
            'model_type' => 'mlb_tabular_bundle',
            'model_version' => 'mlb-tabular-v1',
            'package' => 'picksports_mlb_ml',
            'module' => 'picksports_mlb_ml',
            'model_run_id' => $modelRunId,
            'artifact_id' => $artifactId,
            'generated_at' => now()->utc()->toIso8601String(),
            'dataset_hash' => $datasetHash,
            'feature_schema_version' => 'mlb-pregame-ml-v1',
            'feature_schema_hash' => $featureSchemaHash,
            'config_hash' => $configHash,
            'code_version' => str_repeat('a', 40),
            'source_hash' => hash('sha256', 'mlb-source'),
            'seed' => 20260729,
            'champion_classifier' => 'xgboost',
            'selected_calibrators' => [
                'logistic_regression' => 'platt',
                'xgboost' => 'platt',
            ],
            'training_seasons' => [2025, 2026],
            'chronological_boundaries' => [
                'train_end_at' => '2026-06-01T00:00:00+00:00',
                'test_end_at' => '2026-07-28T00:00:00+00:00',
            ],
            'artifacts' => $inventory,
        ];
        File::put(
            $runDirectory.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        return [
            'run_directory' => $runDirectory,
            'dataset_path' => $datasetPath,
            'dataset_hash' => $datasetHash,
            'model_run_id' => $modelRunId,
            'artifact_id' => $artifactId,
            'config_hash' => $configHash,
        ];
    }
}
