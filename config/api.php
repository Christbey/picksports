<?php

return [
    'v2' => [
        'rate_limits' => [
            'auth_login_per_minute' => (int) env('API_V2_AUTH_LOGIN_RATE_LIMIT_PER_MINUTE', 10),
            'auth_passkey_options_per_minute' => (int) env('API_V2_AUTH_PASSKEY_OPTIONS_RATE_LIMIT_PER_MINUTE', 20),
            'auth_passkey_verify_per_minute' => (int) env('API_V2_AUTH_PASSKEY_VERIFY_RATE_LIMIT_PER_MINUTE', 10),
            'writes_per_minute' => (int) env('API_V2_WRITE_RATE_LIMIT_PER_MINUTE', 60),
        ],
        'idempotency' => [
            'ttl_hours' => (int) env('API_V2_IDEMPOTENCY_TTL_HOURS', 24),
        ],
    ],

    'developer' => [
        'webhooks' => [
            'max_attempts' => (int) env('DEVELOPER_WEBHOOK_MAX_ATTEMPTS', 5),
            'retry_backoff_seconds' => [60, 300, 900, 3600],
        ],
    ],
];
