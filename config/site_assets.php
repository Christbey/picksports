<?php

return [
    'disk' => env('SITE_ASSETS_DISK', env('SPORTS_ASSETS_DISK', 's3')),

    'directory' => trim((string) env('SITE_ASSETS_DIRECTORY', 'site-assets'), '/'),

    'mirror' => env('SITE_ASSETS_MIRROR', true),

    'files' => [
        'share' => [
            'source' => 'picksports-share.png',
            'target' => 'branding/picksports-share.png',
            'content_type' => 'image/png',
        ],
        'icon_512' => [
            'source' => 'icon-512.png',
            'target' => 'branding/icon-512.png',
            'content_type' => 'image/png',
        ],
        'icon_512_maskable' => [
            'source' => 'icon-512-maskable.png',
            'target' => 'branding/icon-512-maskable.png',
            'content_type' => 'image/png',
        ],
        'icon_192' => [
            'source' => 'icon-192.png',
            'target' => 'branding/icon-192.png',
            'content_type' => 'image/png',
        ],
        'apple_touch_icon' => [
            'source' => 'apple-touch-icon.png',
            'target' => 'branding/apple-touch-icon.png',
            'content_type' => 'image/png',
        ],
        'favicon_svg' => [
            'source' => 'favicon.svg',
            'target' => 'branding/favicon.svg',
            'content_type' => 'image/svg+xml',
        ],
    ],
];
