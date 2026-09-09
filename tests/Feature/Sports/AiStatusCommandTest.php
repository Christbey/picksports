<?php

use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use Illuminate\Support\Facades\Artisan;

uses()->group('sports');

it('reports allowlisted ai settings without exposing provider credentials', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-never-print-this-secret');
    config()->set('ai.rate_limits.cooldown_seconds', 900);
    config()->set('ai.rate_limits.quota_cooldown_seconds', 86400);
    config()->set('ai.features.daily_prediction_analysis', [
        'enabled' => true,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'timeout_seconds' => 12,
    ]);

    expect(Artisan::call('ai:status', ['--json' => true]))->toBe(0);

    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($output)->not->toContain('sk-never-print-this-secret')
        ->and($report['schema_version'])->toBe('ai_status_v1')
        ->and($report['default_provider'])->toBe('openai')
        ->and($report['rate_limits'])->toBe([
            'cooldown_seconds' => 900,
            'quota_cooldown_seconds' => 86400,
        ])
        ->and($report['providers']['openai']['credential_configured'])->toBeTrue()
        ->and($report['providers']['openai']['circuit_status'])->toBe('ready')
        ->and($report['features']['daily_prediction_analysis']['enabled'])->toBeTrue();

    expect(Artisan::call('ai:status'))->toBe(0);

    expect(Artisan::output())
        ->not->toContain('sk-never-print-this-secret')
        ->toContain('AI Operational Status')
        ->toContain('configured');
});

it('reports provider cooldown state without revealing the cached value or credential', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'another-secret-value');
    config()->set('ai.rate_limits.quota_cooldown_seconds', 86400);

    app(AiProviderRateLimitCircuitBreaker::class)->trip('openai', quotaExhausted: true);

    expect(Artisan::call('ai:status', ['--json' => true]))->toBe(0);

    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($output)->not->toContain('another-secret-value')
        ->and($report['providers']['openai']['circuit_status'])->toBe('cooldown')
        ->and($report['providers']['openai']['retry_after_seconds'])->toBeGreaterThan(0)
        ->and($report['providers']['openai']['retry_after_seconds'])->toBeLessThanOrEqual(86400);
});
