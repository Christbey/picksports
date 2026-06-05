<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

test('legacy product v1 api responses include deprecation headers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/cbb-brackets?season=2026')
        ->assertOk()
        ->assertHeader('X-API-Deprecated', 'true')
        ->assertHeader('X-API-Replacement', '/api/v2');
});

test('legacy product v1 api usage logging is opt in', function () {
    Config::set('api.v1_usage_logging.enabled', true);
    Log::spy();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/cbb-brackets?season=2026')
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('api.v1.usage', Mockery::on(
            fn (array $context): bool => $context['method'] === 'GET'
                && $context['path'] === 'api/v1/cbb-brackets'
                && $context['user_id'] === $user->id
        ));
});

test('v1 auth routes are not marked as product api deprecations', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeaderMissing('X-API-Deprecated')
        ->assertHeaderMissing('X-API-Replacement');
});
