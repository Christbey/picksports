<?php

return [
    'storage' => [
        'disk' => env('PROVIDER_DATA_DISK', 'provider-local'),
        'prefix' => env('PROVIDER_DATA_PREFIX', 'providers'),
    ],
];
