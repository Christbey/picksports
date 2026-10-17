<?php

return [
    'share_metadata' => env('SNAPSHOT_METADATA_SHARING', false),
    'sports' => ['mlb'],
    'minimum_json_bytes' => 2048,
    'maximum_json_bytes' => 8 * 1024 * 1024,
];
