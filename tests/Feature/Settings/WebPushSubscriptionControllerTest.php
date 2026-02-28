<?php

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\WebPushService;

test('web push config endpoint returns current state for authenticated user', function () {
    $user = User::factory()->create();

    WebPushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/subscription/abc123',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    $service = Mockery::mock(WebPushService::class);
    $service->shouldReceive('isConfigured')->once()->andReturn(true);
    $service->shouldReceive('publicKey')->once()->andReturn('vapid-public-key');
    $this->app->instance(WebPushService::class, $service);

    $response = $this->actingAs($user)->get(route('web-push.config'));

    $response->assertOk()->assertJson([
        'configured' => true,
        'publicKey' => 'vapid-public-key',
        'hasSubscription' => true,
    ]);
});

test('web push store endpoint saves a subscription', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('web-push.subscriptions.store'), [
        'endpoint' => 'https://push.example.com/subscription/xyz789',
        'contentEncoding' => 'aes128gcm',
        'keys' => [
            'p256dh' => 'fake-client-public-key',
            'auth' => 'fake-auth-token',
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);

    $this->assertDatabaseHas('web_push_subscriptions', [
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/subscription/xyz789',
        'content_encoding' => 'aes128gcm',
    ]);
});

test('web push destroy endpoint removes a subscription', function () {
    $user = User::factory()->create();

    $subscription = WebPushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/subscription/to-delete',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    $response = $this->actingAs($user)->deleteJson(route('web-push.subscriptions.destroy'), [
        'endpoint' => $subscription->endpoint,
    ]);

    $response->assertOk()->assertJson([
        'ok' => true,
        'deleted' => 1,
    ]);

    $this->assertDatabaseMissing('web_push_subscriptions', [
        'id' => $subscription->id,
    ]);
});

test('web push test endpoint returns validation error when push is not configured', function () {
    $user = User::factory()->create();

    $service = Mockery::mock(WebPushService::class);
    $service->shouldReceive('isConfigured')->once()->andReturn(false);
    $this->app->instance(WebPushService::class, $service);

    $response = $this->actingAs($user)->postJson(route('web-push.test'));

    $response->assertStatus(422)->assertJson([
        'ok' => false,
        'message' => 'Web push is not configured on the server.',
    ]);
});
