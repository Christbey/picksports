<?php

return [
    'storage' => [
        'disk' => env('ML_FILESYSTEM_DISK', 'ml-local'),
        'prefix' => env('ML_STORAGE_PREFIX', 'ml'),
        'cache_disk' => env('ML_CACHE_DISK', 'ml-cache'),
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
