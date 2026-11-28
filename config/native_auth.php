<?php

return [
    'epoch_issuance_enabled' => env('NATIVE_CREDENTIAL_EPOCH_ISSUANCE_ENABLED', false),
    'access_token_ttl_minutes' => env('NATIVE_ACCESS_TOKEN_TTL_MINUTES', 15),
    'refresh_token_ttl_days' => env('NATIVE_REFRESH_TOKEN_TTL_DAYS', 30),
    'abilities' => ['mobile:read', 'mobile:write'],
];
