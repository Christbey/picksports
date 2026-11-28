<?php

return [
    'enabled' => (bool) env('PROVIDER_REFRESH_FENCING_ENABLED', false),
    'write_target_fingerprint' => env('PROVIDER_REFRESH_WRITE_TARGET_FINGERPRINT'),
    'protocol_version' => 1,
    'deadline_seconds' => 90,
    'lease_seconds' => 120,
    'maximum_response_bytes' => 256 * 1024,
    'maximum_acquisition_bytes' => 1024 * 1024,
    'maximum_rows' => 2000,
    'maximum_json_tokens' => 20000,
    'maximum_acquisition_json_tokens' => 40000,
    'maximum_json_depth' => 64,
    'maximum_references' => 100,
];
