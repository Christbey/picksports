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

    'period_models' => [
        'enabled' => (bool) env('MLB_PERIOD_ML_ENABLED', true),
        'schema_path' => base_path('ml/mlb/config/period_feature_schema.yaml'),
        'work_directory' => storage_path('app/ml/automated-training/mlb-period'),
        'timeout_seconds' => (int) env('MLB_PERIOD_ML_TRAINING_TIMEOUT', 14_400),
        'inference_timeout_seconds' => (float) env('MLB_PERIOD_ML_INFERENCE_TIMEOUT', 30),
        'shadow_game_limit' => (int) env('MLB_PERIOD_ML_SHADOW_GAME_LIMIT', 30),
        'history_start_season' => (int) env('MLB_PERIOD_ML_HISTORY_START_SEASON', 2021),
        'minimum_edge' => (float) env('MLB_PERIOD_ML_MINIMUM_EDGE', 0.03),
        'maximum_uncertainty' => (float) env('MLB_PERIOD_ML_MAXIMUM_UNCERTAINTY', 0.92),
        'feature_snapshot_version' => 'mlb-period-live-v1',
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
