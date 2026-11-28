<?php

return [
    // Activate only after every authenticating runtime has the epoch guard and schema.
    'finite_issuance_enabled' => env('API_TOKEN_FINITE_ISSUANCE_ENABLED', false),
    // Applies only to newly issued password/passkey API credentials.
    'ttl_minutes' => env('API_TOKEN_TTL_MINUTES', 60),
];
