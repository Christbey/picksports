<?php

$nullableFloat = static function (string $key, float|int|null $default = null): ?float {
    $value = env($key, $default);

    return $value === null || $value === '' ? null : (float) $value;
};

$queueNames = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('INFRASTRUCTURE_HEALTH_QUEUE_NAMES', 'default,sync')),
)));

return [
    'health' => [
        'database_connection' => env('INFRASTRUCTURE_HEALTH_DATABASE_CONNECTION'),
        // Laravel Cloud currently provisions the production database with a
        // 20 GiB volume. Override this whenever the service tier changes.
        'database_storage_capacity_gb' => $nullableFloat('INFRASTRUCTURE_HEALTH_DATABASE_STORAGE_CAPACITY_GB', 20),
        'queue_connection' => env('INFRASTRUCTURE_HEALTH_QUEUE_CONNECTION'),
        'queue_names' => $queueNames === [] ? ['default'] : $queueNames,
        'redis_connection' => env('INFRASTRUCTURE_HEALTH_REDIS_CONNECTION'),
        'failed_jobs_window_minutes' => max(1, (int) env('INFRASTRUCTURE_HEALTH_FAILED_JOBS_WINDOW_MINUTES', 60)),
        'scheduler_window_minutes' => max(1, (int) env('INFRASTRUCTURE_HEALTH_SCHEDULER_WINDOW_MINUTES', 60)),
        'fail_on_unavailable' => (bool) env('INFRASTRUCTURE_HEALTH_FAIL_ON_UNAVAILABLE', true),

        // Thresholds are upper bounds. A null threshold disables that severity.
        'thresholds' => [
            'redis_memory_percent' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_REDIS_MEMORY_WARNING_PERCENT', 70),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_REDIS_MEMORY_CRITICAL_PERCENT', 85),
            ],
            'redis_evicted_keys' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_REDIS_EVICTIONS_WARNING', 0),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_REDIS_EVICTIONS_CRITICAL'),
            ],
            'queue_ready' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_QUEUE_READY_WARNING', 100),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_QUEUE_READY_CRITICAL', 200),
            ],
            'queue_oldest_age_seconds' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_QUEUE_OLDEST_WARNING_SECONDS', 180),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_QUEUE_OLDEST_CRITICAL_SECONDS', 300),
            ],
            'failed_jobs' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_FAILED_JOBS_WARNING', 0),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_FAILED_JOBS_CRITICAL', 0),
            ],
            'scheduler_failures' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_SCHEDULER_FAILURES_WARNING', 0),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_SCHEDULER_FAILURES_CRITICAL', 0),
            ],
            'database_storage_percent' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_DATABASE_STORAGE_WARNING_PERCENT', 70),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_DATABASE_STORAGE_CRITICAL_PERCENT', 85),
            ],
            'database_connections_percent' => [
                'warning' => $nullableFloat('INFRASTRUCTURE_HEALTH_DATABASE_CONNECTIONS_WARNING_PERCENT', 60),
                'critical' => $nullableFloat('INFRASTRUCTURE_HEALTH_DATABASE_CONNECTIONS_CRITICAL_PERCENT', 80),
            ],
        ],
    ],
];
