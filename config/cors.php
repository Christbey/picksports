<?php

return [
    'paths' => [
        'api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'two-factor-challenge',
        'forgot-password', 'reset-password', 'email/verification-notification',
    ],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', env('APP_URL', '')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-ID', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => true,
];
