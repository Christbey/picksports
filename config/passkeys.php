<?php

return [
    'enabled' => env('PASSKEYS_ENABLED', true),

    // Leave null to derive from current request host.
    'rp_id' => env('PASSKEYS_RP_ID'),

    // Leave null to derive from current request origin.
    'origin' => env('PASSKEYS_ORIGIN'),

    'challenge_timeout_seconds' => (int) env('PASSKEYS_CHALLENGE_TIMEOUT', 600),

    'authentication_timeout_ms' => (int) env('PASSKEYS_AUTH_TIMEOUT_MS', 20000),

    'user_verification' => env('PASSKEYS_USER_VERIFICATION', 'required'),

    'algorithms' => [-7], // ES256
];
