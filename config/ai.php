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
    'validation_review_summary' => [
        'enabled' => (bool) env('AI_VALIDATION_REVIEW_SUMMARY_ENABLED', false),
        'provider' => env('AI_VALIDATION_REVIEW_SUMMARY_PROVIDER', 'openai'),
        'model' => env('AI_VALIDATION_REVIEW_SUMMARY_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_VALIDATION_REVIEW_SUMMARY_TIMEOUT_SECONDS', 8),
    ],
    'daily_digest_summary' => [
        'enabled' => (bool) env('AI_DAILY_DIGEST_SUMMARY_ENABLED', false),
        'provider' => env('AI_DAILY_DIGEST_SUMMARY_PROVIDER', 'openai'),
        'model' => env('AI_DAILY_DIGEST_SUMMARY_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_DAILY_DIGEST_SUMMARY_TIMEOUT_SECONDS', 8),
    ],
    'daily_prediction_analysis' => [
        'enabled' => (bool) env('AI_DAILY_PREDICTION_ANALYSIS_ENABLED', true),
        'prompt_version' => env('AI_DAILY_PREDICTION_ANALYSIS_PROMPT_VERSION', 'daily-prediction-analysis-v1'),
        'provider' => env('AI_DAILY_PREDICTION_ANALYSIS_PROVIDER', 'openai'),
        'model' => env('AI_DAILY_PREDICTION_ANALYSIS_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_DAILY_PREDICTION_ANALYSIS_TIMEOUT_SECONDS', 12),
    ],
    'nfl_game_context_research' => [
        'enabled' => (bool) env('AI_NFL_GAME_CONTEXT_RESEARCH_ENABLED', true),
        'prompt_version' => env('AI_NFL_GAME_CONTEXT_RESEARCH_PROMPT_VERSION', 'nfl-game-context-research-v1'),
        'provider' => env('AI_NFL_GAME_CONTEXT_RESEARCH_PROVIDER', 'openai'),
        'model' => env('AI_NFL_GAME_CONTEXT_RESEARCH_MODEL', 'gpt-5.6-luna'),
        'timeout_seconds' => env('AI_NFL_GAME_CONTEXT_RESEARCH_TIMEOUT_SECONDS', 60),
        'max_searches' => env('AI_NFL_GAME_CONTEXT_RESEARCH_MAX_SEARCHES', 5),
        'max_output_tokens' => env('AI_NFL_GAME_CONTEXT_RESEARCH_MAX_OUTPUT_TOKENS', 6000),
        'reasoning_effort' => env('AI_NFL_GAME_CONTEXT_RESEARCH_REASONING_EFFORT', 'none'),
        'freshness_minutes' => env('AI_NFL_GAME_CONTEXT_RESEARCH_FRESHNESS_MINUTES', 360),
        'minimum_adjustment_confidence' => env('AI_NFL_GAME_CONTEXT_RESEARCH_MINIMUM_ADJUSTMENT_CONFIDENCE', 55),
        'require_provider_citations' => (bool) env('AI_NFL_GAME_CONTEXT_RESEARCH_REQUIRE_PROVIDER_CITATIONS', true),
        'pricing' => [
            'model' => env('AI_NFL_GAME_CONTEXT_RESEARCH_PRICING_MODEL', 'gpt-5.6-luna'),
            'input_per_million' => env('AI_NFL_GAME_CONTEXT_RESEARCH_INPUT_PER_MILLION', 0.20),
            'cached_input_per_million' => env('AI_NFL_GAME_CONTEXT_RESEARCH_CACHED_INPUT_PER_MILLION', 0.02),
            'output_per_million' => env('AI_NFL_GAME_CONTEXT_RESEARCH_OUTPUT_PER_MILLION', 1.20),
            'web_search_per_call' => env('AI_NFL_GAME_CONTEXT_RESEARCH_WEB_SEARCH_PER_CALL', 0.01),
        ],
    ],
    'data_freshness_review' => [
        'enabled' => (bool) env('AI_DATA_FRESHNESS_REVIEW_ENABLED', true),
        'provider' => env('AI_DATA_FRESHNESS_REVIEW_PROVIDER', 'openai'),
        'model' => env('AI_DATA_FRESHNESS_REVIEW_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_DATA_FRESHNESS_REVIEW_TIMEOUT_SECONDS', 8),
    ],
    'market_readiness_review' => [
        'enabled' => (bool) env('AI_MARKET_READINESS_REVIEW_ENABLED', true),
        'provider' => env('AI_MARKET_READINESS_REVIEW_PROVIDER', 'openai'),
        'model' => env('AI_MARKET_READINESS_REVIEW_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_MARKET_READINESS_REVIEW_TIMEOUT_SECONDS', 8),
    ],
    'model_audit_review' => [
        'enabled' => (bool) env('AI_MODEL_AUDIT_REVIEW_ENABLED', true),
        'provider' => env('AI_MODEL_AUDIT_REVIEW_PROVIDER', 'openai'),
        'model' => env('AI_MODEL_AUDIT_REVIEW_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_MODEL_AUDIT_REVIEW_TIMEOUT_SECONDS', 8),
    ],
    'publishing_guardrail_review' => [
        'enabled' => (bool) env('AI_PUBLISHING_GUARDRAIL_REVIEW_ENABLED', true),
        'enforced' => (bool) env('AI_PUBLISHING_GUARDRAILS_ENFORCED', false),
        'provider' => env('AI_PUBLISHING_GUARDRAIL_REVIEW_PROVIDER', 'openai'),
        'model' => env('AI_PUBLISHING_GUARDRAIL_REVIEW_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => env('AI_PUBLISHING_GUARDRAIL_REVIEW_TIMEOUT_SECONDS', 8),
    ],
];

return $config;
