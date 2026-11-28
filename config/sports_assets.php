<?php

return [
    'disk' => env('SPORTS_ASSETS_DISK', 's3'),

    'directory' => trim((string) env('SPORTS_ASSETS_DIRECTORY', 'sports'), '/'),

    'mirror' => env('SPORTS_ASSETS_MIRROR', false),

    // Admit only operator-reviewed exact provider hostnames; no wildcard trust.
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPORTS_ASSETS_ALLOWED_HOSTS', ''))))),
    'maximum_bytes' => 5 * 1024 * 1024,
    'maximum_dimension' => 4096,
];
