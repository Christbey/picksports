<?php

return [
    'mode' => env('MLB_ML_MODE', 'shadow'),

    'shadow' => [
        'enabled' => (bool) env('MLB_ML_SHADOW_ENABLED', true),
        'artifact_id' => env('MLB_ML_SHADOW_ARTIFACT_ID'),
        'auto_select' => (bool) env('MLB_ML_SHADOW_AUTO_SELECT', true),
        'require_probable_pitchers' => (bool) env('MLB_ML_REQUIRE_PROBABLE_PITCHERS', true),
        'required_features' => [
            'feature_home_team_elo',
            'feature_away_team_elo',
            'feature_home_pitcher_elo',
            'feature_away_pitcher_elo',
        ],
        'max_uncertainty' => is_numeric(env('MLB_ML_SHADOW_MAX_UNCERTAINTY'))
            ? (float) env('MLB_ML_SHADOW_MAX_UNCERTAINTY')
            : null,
    ],

    'process' => [
        'package_directory' => base_path('ml/mlb'),
        'command' => [
            env('MLB_ML_PYTHON_BINARY', 'python3'),
            '-m',
            env('MLB_ML_PYTHON_MODULE', 'picksports_mlb_ml'),
        ],
        'timeout_seconds' => (float) env('MLB_ML_INFERENCE_TIMEOUT', 30),
    ],

    'weekly_training' => [
        'enabled' => (bool) env('MLB_ML_WEEKLY_TRAINING_ENABLED', true),
        'auto_promote' => (bool) env('MLB_ML_AUTO_PROMOTE_ENABLED', true),
        'history_seasons' => (int) env('MLB_ML_TRAINING_HISTORY_SEASONS', 6),
        'schema_path' => base_path('ml/mlb/config/feature_schema.yaml'),
        'package_directory' => base_path('ml/mlb'),
        'python_command' => [
            env('MLB_ML_TRAINING_PYTHON_BINARY', env('MLB_ML_PYTHON_BINARY', 'python3')),
            '-m',
            env('MLB_ML_PYTHON_MODULE', 'picksports_mlb_ml'),
        ],
        'work_directory' => storage_path('app/ml/automated-training/mlb'),
        'timeout_seconds' => (int) env('MLB_ML_TRAINING_TIMEOUT', 14_400),
        'lock_seconds' => (int) env('MLB_ML_TRAINING_LOCK_SECONDS', 18_000),
        'threads' => (int) env('MLB_ML_TRAINING_THREADS', 2),
        'retention_days' => (int) env('MLB_ML_TRAINING_RETENTION_DAYS', 14),
    ],

    'bundle' => [
        'staging_directory' => storage_path('app/ml/mlb-tabular/staging'),
        'extraction_directory' => storage_path('app/ml/mlb-tabular/extracted'),
        'input_directory' => storage_path('app/ml/mlb-tabular/inputs'),
        'max_entries' => (int) env('MLB_ML_BUNDLE_MAX_ENTRIES', 64),
        'max_file_bytes' => (int) env('MLB_ML_BUNDLE_MAX_FILE_BYTES', 268_435_456),
        'max_uncompressed_bytes' => (int) env('MLB_ML_BUNDLE_MAX_UNCOMPRESSED_BYTES', 1_073_741_824),
    ],
];
