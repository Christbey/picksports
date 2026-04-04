<?php

$forgeHeartbeats = [];

foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
    if (! is_string($key) || ! is_string($value)) {
        continue;
    }

    if (! str_starts_with($key, 'FORGE_HEARTBEAT_') || ! str_ends_with($key, '_URL')) {
        continue;
    }

    $normalizedKey = strtolower(substr($key, strlen('FORGE_HEARTBEAT_'), -strlen('_URL')));
    if ($normalizedKey !== '') {
        $forgeHeartbeats[$normalizedKey] = $value;
    }
}

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

    'vonage' => [
        'sms_from' => env('VONAGE_SMS_FROM'),
        'key' => env('VONAGE_KEY'),
        'secret' => env('VONAGE_SECRET'),
    ],

    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
        'base_url' => 'https://api.the-odds-api.com/v4',
    ],

    'scores_and_odds' => [
        'base_url' => env('SCORES_AND_ODDS_BASE_URL', 'https://www.scoresandodds.com'),
    ],

    'collegefootballdata' => [
        'api_key' => env('COLLEGEFOOTBALLDATA_API_KEY'),
        'base_url' => env('COLLEGEFOOTBALLDATA_BASE_URL', 'https://api.collegefootballdata.com'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'oauth' => [
        'providers' => [
            'google' => [
                'enabled' => (bool) env('GOOGLE_OAUTH_ENABLED', false),
                'label' => 'Google',
            ],
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Forge Heartbeat Ping URLs
    |--------------------------------------------------------------------------
    |
    | Automatically loads env vars matching FORGE_HEARTBEAT_*_URL, where the
    | * is a snake_case scheduled task name.
    |
    | Example:
    |   Schedule name: NBA: Live Scoreboard Sync
    |   Env var: FORGE_HEARTBEAT_NBA_LIVE_SCOREBOARD_SYNC_URL
    |
    */
    'forge' => [
        'heartbeats' => $forgeHeartbeats,
    ],

    'web_push' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT', 'mailto:support@example.com'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
    ],

    'twilio_whatsapp' => [
        'account_sid' => env('TWILIO_WHATSAPP_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_WHATSAPP_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM'),
    ],

];
