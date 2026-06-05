<?php

return [
    'v1_usage_logging' => [
        'enabled' => env('API_V1_USAGE_LOGGING_ENABLED', false),
        'deprecation_headers' => env('API_V1_DEPRECATION_HEADERS_ENABLED', true),
    ],
];
