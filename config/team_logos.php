<?php

return [
    'disk' => env('TEAM_LOGOS_DISK', 's3'),

    'directory' => trim((string) env('TEAM_LOGOS_DIRECTORY', 'team-logos'), '/'),

    'mirror' => env('TEAM_LOGOS_MIRROR', false),
];
