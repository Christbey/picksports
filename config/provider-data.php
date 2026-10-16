<?php

return [
    'storage' => [
        'disk' => env('PROVIDER_DATA_DISK', 'provider-local'),
        'prefix' => env('PROVIDER_DATA_PREFIX', 'providers'),
    ],

    'payloads' => [
        'max_json_bytes' => 8 * 1024 * 1024,
        'max_compressed_bytes' => 8 * 1024 * 1024,
    ],
];
