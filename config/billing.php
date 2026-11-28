<?php

return [
    'entitlements' => ['enabled' => env('BILLING_ENTITLEMENT_PROVENANCE_ENABLED', false)],
    'durable' => ['enabled' => env('BILLING_DURABLE_WEBHOOKS_ENABLED', false)],
    'account' => [
        'platform_id' => env('BILLING_STRIPE_ACCOUNT_ID'),
        'livemode' => env('BILLING_STRIPE_LIVEMODE'),
        'api_origin' => env('BILLING_STRIPE_API_ORIGIN', 'https://api.stripe.com'),
    ],
    'portal' => ['configuration_id' => env('BILLING_STRIPE_PORTAL_CONFIGURATION_ID')],
    'maximum_event_bytes' => 262144,
    'maximum_attempts' => 8,
    'maximum_targets' => 4,
    'maximum_items' => 100,
    'maximum_item_pages' => 3,
    'maximum_provider_calls' => 8,
    'fetch_budget_seconds' => 60,
    'lease_seconds' => 120,
];
