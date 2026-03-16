<?php

return [
    'disk' => env('SPORTS_ASSETS_DISK', 's3'),

    'directory' => trim((string) env('SPORTS_ASSETS_DIRECTORY', 'sports'), '/'),

    'mirror' => env('SPORTS_ASSETS_MIRROR', false),
];
