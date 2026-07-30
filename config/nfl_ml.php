<?php

return [
    'shadow' => [
        'enabled' => (bool) env('NFL_ML_SHADOW_ENABLED', true),
        'artifact_id' => env('NFL_ML_SHADOW_ARTIFACT_ID'),
        'auto_select' => (bool) env('NFL_ML_SHADOW_AUTO_SELECT', true),
        'max_uncertainty' => is_numeric(env('NFL_ML_SHADOW_MAX_UNCERTAINTY'))
            ? (float) env('NFL_ML_SHADOW_MAX_UNCERTAINTY')
            : null,
    ],

    'process' => [
        'package_directory' => base_path('ml/nfl'),
        'command' => [
            env('NFL_ML_PYTHON_BINARY', 'python3'),
            '-m',
            env('NFL_ML_PYTHON_MODULE', 'picksports_nfl_ml'),
        ],
        'timeout_seconds' => (float) env('NFL_ML_INFERENCE_TIMEOUT', 30),
    ],

    'weekly_training' => [
        'enabled' => (bool) env('NFL_ML_WEEKLY_TRAINING_ENABLED', true),
        'auto_promote' => (bool) env('NFL_ML_AUTO_PROMOTE_ENABLED', true),
        'from_season' => (int) env('NFL_ML_TRAINING_FROM_SEASON', 2017),
        'feature_version' => env('NFL_ML_TRAINING_FEATURE_VERSION', 'nfl-pregame-ml-v3'),
        'profile' => env('NFL_ML_TRAINING_PROFILE', 'full-historical'),
        'schema_path' => base_path('ml/nfl/config/feature_schema_v3.yaml'),
        'package_directory' => base_path('ml/nfl'),
        'python_command' => [
            env('NFL_ML_TRAINING_PYTHON_BINARY', env('NFL_ML_PYTHON_BINARY', 'python3')),
            '-m',
            env('NFL_ML_PYTHON_MODULE', 'picksports_nfl_ml'),
        ],
        'work_directory' => storage_path('app/ml/automated-training/nfl'),
        'timeout_seconds' => (int) env('NFL_ML_TRAINING_TIMEOUT', 14_400),
        'lock_seconds' => (int) env('NFL_ML_TRAINING_LOCK_SECONDS', 18_000),
        'threads' => (int) env('NFL_ML_TRAINING_THREADS', 2),
        'tune' => (bool) env('NFL_ML_WEEKLY_TUNING_ENABLED', false),
        'explain' => (bool) env('NFL_ML_WEEKLY_SHAP_ENABLED', false),
        'retention_days' => (int) env('NFL_ML_TRAINING_RETENTION_DAYS', 14),
    ],

    'bundle' => [
        'staging_directory' => storage_path('app/ml/nfl-tabular/staging'),
        'extraction_directory' => storage_path('app/ml/nfl-tabular/extracted'),
        'input_directory' => storage_path('app/ml/nfl-tabular/inputs'),
        'max_entries' => (int) env('NFL_ML_BUNDLE_MAX_ENTRIES', 64),
        'max_file_bytes' => (int) env('NFL_ML_BUNDLE_MAX_FILE_BYTES', 268_435_456),
        'max_uncompressed_bytes' => (int) env('NFL_ML_BUNDLE_MAX_UNCOMPRESSED_BYTES', 1_073_741_824),
    ],
];
