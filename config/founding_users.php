<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Founding User Access
    |--------------------------------------------------------------------------
    |
    | Enable a "first X users are free" program managed through a dedicated role.
    | Users with this role bypass paid subscription checks and inherit the
    | configured tier's feature set.
    |
    */
    'enabled' => env('FOUNDING_USERS_ENABLED', false),
    'limit' => (int) env('FOUNDING_USERS_LIMIT', 0),
    'role' => env('FOUNDING_USERS_ROLE', 'founding_user'),
    'tier_slug' => env('FOUNDING_USERS_TIER_SLUG', 'premium'),
];

