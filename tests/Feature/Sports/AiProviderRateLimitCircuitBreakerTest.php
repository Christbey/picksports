<?php

use App\AI\Agents\SportsPredictionNarrativeAgent;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Facades\Cache;
use Mockery as m;

uses()->group('sports', 'ai');

it('opens a shared provider cooldown after a narrative rate limit', function () {
    Cache::flush();
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.rate_limits.cooldown_seconds', 900);

    $agent = m::mock(SportsPredictionNarrativeAgent::class);
    $agent->shouldReceive('prompt')
        ->once()
        ->andThrow(new RuntimeException('Application rate limited by AI provider [openai].'));
    $this->app->instance(SportsPredictionNarrativeAgent::class, $agent);

    $service = app(SportsAiContentService::class);

    expect($service->generatePredictionNarrative('first request', 'openai', 'gpt-4o-mini'))->toBeNull()
        ->and(app(AiProviderRateLimitCircuitBreaker::class)->retryAfterSeconds('openai'))->toBeGreaterThan(0)
        ->and($service->generatePredictionNarrative('second request', 'openai', 'gpt-4o-mini'))->toBeNull()
        ->and($service->providerAvailabilityMessage('openai'))->toContain('rate-limit cooldown');
});

it('treats exhausted provider quota as a rate limit with the longer cooldown', function () {
    Cache::flush();
    config()->set('ai.rate_limits.quota_cooldown_seconds', 86400);

    $breaker = app(AiProviderRateLimitCircuitBreaker::class);
    $message = 'Provider failed with insufficient_quota.';

    expect($breaker->isRateLimitFailure($message))->toBeTrue()
        ->and($breaker->isQuotaExhausted($message))->toBeTrue()
        ->and($breaker->trip('openai', true))->toBe(86400)
        ->and($breaker->retryAfterSeconds('openai'))->toBeGreaterThanOrEqual(86399);
});
