<?php

$config = require base_path('vendor/laravel/ai/config/ai.php');

$config['default'] = env('AI_DEFAULT_PROVIDER', 'openai');
$config['providers']['openai']['key'] = env('OPENAI_API_KEY');
$config['providers']['openai']['url'] = env(
    'OPENAI_BASE_URL',
    env('OPENAI_URL', 'https://api.openai.com/v1')
);

$config['features'] = [
    'sports_prediction_narratives' => [
        'provider' => env('AI_SPORTS_NARRATIVE_PROVIDER', 'openai'),
        'model' => env('AI_SPORTS_NARRATIVE_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_SPORTS_NARRATIVE_TIMEOUT_SECONDS', 8),
    ],
    'player_prop_narratives' => [
        'provider' => env('AI_PLAYER_PROP_NARRATIVE_PROVIDER', 'openai'),
        'model' => env('AI_PLAYER_PROP_NARRATIVE_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_PLAYER_PROP_NARRATIVE_TIMEOUT_SECONDS', 8),
    ],
    'daily_digest_summary' => [
        'enabled' => (bool) env('AI_DAILY_DIGEST_SUMMARY_ENABLED', false),
        'provider' => env('AI_DAILY_DIGEST_SUMMARY_PROVIDER', 'openai'),
        'model' => env('AI_DAILY_DIGEST_SUMMARY_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_DAILY_DIGEST_SUMMARY_TIMEOUT_SECONDS', 8),
    ],
];

return $config;
