<?php

return [
    'access_token_ttl_minutes' => (int) env('NATIVE_ACCESS_TOKEN_TTL_MINUTES', 15),
    'refresh_token_ttl_days' => (int) env('NATIVE_REFRESH_TOKEN_TTL_DAYS', 30),
    'abilities' => ['mobile:read', 'mobile:write'],
];
