<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
        'base_url' => 'https://api.the-odds-api.com/v4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule Heartbeat Ping URLs
    |--------------------------------------------------------------------------
    |
    | External monitoring URLs (e.g. BetterStack, OhDear, Envoyer) that get
    | pinged on success/failure of scheduled live scoreboard syncs.
    | Set these in .env to enable external heartbeat monitoring.
    |
    */

    'heartbeat' => [
        'live_scoreboard_url' => env('HEARTBEAT_LIVE_SCOREBOARD_URL'),
    ],

    'web_push' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT', 'mailto:support@example.com'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
    ],

];
