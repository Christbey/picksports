<?php

return [
    'storage' => [
        'disk' => env('ML_FILESYSTEM_DISK', 'ml-local'),
        'prefix' => env('ML_STORAGE_PREFIX', 'ml'),
        'cache_disk' => env('ML_CACHE_DISK', 'ml-cache'),
    ],

    'training' => [
        'maximum_attempts' => (int) env('ML_TRAINING_MAXIMUM_ATTEMPTS', 3),
    ],

    'remote' => [
        'enabled' => (bool) env('ML_REMOTE_TRAINING_ENABLED', false),
        'maximum_file_bytes' => 268_435_456,
        'maximum_total_bytes' => 1_073_741_824,
        'maximum_files' => 64,
        'maximum_manifest_bytes' => 262_144,
        'maximum_report_bytes' => 2_097_152,
        'upload_lease_seconds' => 600,
        'maximum_concurrent_uploads' => 4,
        'maximum_staging_bytes' => 536_870_912,
        'staging_directory' => storage_path('app/private/ml-remote-staging'),
    ],

    'monitoring' => ['enabled' => (bool) env('ML_TRAINING_MONITORING_ENABLED', false)],

    'archive' => [
        'python_binary' => env('ML_PARQUET_PYTHON_BINARY', 'python3'),
        'parquet_writer' => base_path('scripts/export_jsonl_to_parquet.py'),
    ],

    'promotion' => [
        'minimum_windows' => (int) env('ML_PROMOTION_MINIMUM_WINDOWS', 3),
        'minimum_better_window_rate' => (float) env('ML_PROMOTION_MINIMUM_BETTER_WINDOW_RATE', 0.60),
        'maximum_worst_window_regression' => [
            'brier' => (float) env('ML_PROMOTION_MAX_BRIER_REGRESSION', 0.02),
            'log_loss' => (float) env('ML_PROMOTION_MAX_LOG_LOSS_REGRESSION', 0.10),
            'mae' => (float) env('ML_PROMOTION_MAX_MAE_REGRESSION', 1.00),
        ],
        'live_shadow' => [
            'minimum_observations' => (int) env('ML_PROMOTION_MINIMUM_LIVE_SHADOW_OBSERVATIONS', 25),
            'minimum_settled_decisions' => (int) env('ML_PROMOTION_MINIMUM_SETTLED_SHADOW_DECISIONS', 10),
        ],
    ],
];
