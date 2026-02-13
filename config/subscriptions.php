<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Tiers
    |--------------------------------------------------------------------------
    |
    | Define the available subscription tiers for your application.
    | Each tier includes Stripe price IDs for monthly and yearly billing
    | periods, as well as permissions and feature access.
    |
    */

    'tiers' => [
        'free' => [
            'name' => 'Free',
            'description' => 'Access to basic predictions and limited features',
            'price' => [
                'monthly' => null,
                'yearly' => null,
            ],
            'stripe_price_id' => [
                'monthly' => null,
                'yearly' => null,
            ],
            'features' => [
                'predictions_per_day' => 5,
                'historical_data_days' => 7,
                'sports_access' => ['NBA', 'NFL'],
                'export_predictions' => false,
                'api_access' => false,
                'advanced_analytics' => false,
                'email_alerts' => false,
                'priority_support' => false,
            ],
            'permissions' => [
                'view_predictions',
                'view_dashboard',
            ],
        ],

        'basic' => [
            'name' => 'Basic',
            'description' => 'Expanded access to predictions across all sports',
            'price' => [
                'monthly' => 9.99,
                'yearly' => 99.99,
            ],
            'stripe_price_id' => [
                'monthly' => env('STRIPE_PRICE_BASIC_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_BASIC_YEARLY'),
            ],
            'features' => [
                'predictions_per_day' => 25,
                'historical_data_days' => 30,
                'sports_access' => ['NBA', 'NFL', 'CBB', 'WCBB', 'MLB', 'CFB'],
                'export_predictions' => true,
                'api_access' => false,
                'advanced_analytics' => false,
                'email_alerts' => true,
                'priority_support' => false,
            ],
            'permissions' => [
                'view_predictions',
                'view_dashboard',
                'export_data',
                'email_notifications',
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'description' => 'Advanced analytics and unlimited predictions',
            'price' => [
                'monthly' => 29.99,
                'yearly' => 299.99,
            ],
            'stripe_price_id' => [
                'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
            ],
            'features' => [
                'predictions_per_day' => null, // unlimited
                'historical_data_days' => 365,
                'sports_access' => ['NBA', 'NFL', 'CBB', 'WCBB', 'MLB', 'CFB', 'WNBA'],
                'export_predictions' => true,
                'api_access' => true,
                'advanced_analytics' => true,
                'email_alerts' => true,
                'priority_support' => false,
            ],
            'permissions' => [
                'view_predictions',
                'view_dashboard',
                'export_data',
                'email_notifications',
                'api_access',
                'advanced_analytics',
            ],
        ],

        'premium' => [
            'name' => 'Premium',
            'description' => 'Full access with priority support and custom features',
            'price' => [
                'monthly' => 99.99,
                'yearly' => 999.99,
            ],
            'stripe_price_id' => [
                'monthly' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
                'yearly' => env('STRIPE_PRICE_PREMIUM_YEARLY'),
            ],
            'features' => [
                'predictions_per_day' => null, // unlimited
                'historical_data_days' => null, // unlimited
                'sports_access' => ['NBA', 'NFL', 'CBB', 'WCBB', 'MLB', 'CFB', 'WNBA'],
                'export_predictions' => true,
                'api_access' => true,
                'advanced_analytics' => true,
                'email_alerts' => true,
                'priority_support' => true,
            ],
            'permissions' => [
                'view_predictions',
                'view_dashboard',
                'export_data',
                'email_notifications',
                'api_access',
                'advanced_analytics',
                'priority_support',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Tier
    |--------------------------------------------------------------------------
    |
    | The default tier assigned to new users without an active subscription.
    |
    */

    'default_tier' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Trial Configuration
    |--------------------------------------------------------------------------
    |
    | Trial period settings. Set to null for no trial period.
    |
    */

    'trial' => [
        'enabled' => false,
        'days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing Periods
    |--------------------------------------------------------------------------
    |
    | Available billing periods for subscriptions.
    |
    */

    'billing_periods' => [
        'monthly' => 'month',
        'yearly' => 'year',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace Period
    |--------------------------------------------------------------------------
    |
    | Days of grace period after subscription ends before access is revoked.
    |
    */

    'grace_period_days' => env('SUBSCRIPTION_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Global feature flags that can be toggled independently of tiers.
    |
    */

    'features' => [
        'api_enabled' => env('FEATURE_API_ENABLED', true),
        'exports_enabled' => env('FEATURE_EXPORTS_ENABLED', true),
        'analytics_enabled' => env('FEATURE_ANALYTICS_ENABLED', true),
        'email_alerts_enabled' => env('FEATURE_EMAIL_ALERTS_ENABLED', true),
    ],

];
