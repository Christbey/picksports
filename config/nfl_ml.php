<?php

return [
    'shadow' => [
        'enabled' => (bool) env('NFL_ML_SHADOW_ENABLED', false),
        'artifact_id' => env('NFL_ML_SHADOW_ARTIFACT_ID'),
        'max_uncertainty' => is_numeric(env('NFL_ML_SHADOW_MAX_UNCERTAINTY'))
            ? (float) env('NFL_ML_SHADOW_MAX_UNCERTAINTY')
            : null,
    ],

    'process' => [
        'command' => [
            env('NFL_ML_PYTHON_BINARY', 'python3'),
            '-m',
            env('NFL_ML_PYTHON_MODULE', 'picksports_nfl_ml'),
        ],
        'timeout_seconds' => (float) env('NFL_ML_INFERENCE_TIMEOUT', 30),
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
