<?php

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

it('issues and rotates a native device session through the v2 api', function () {
    $user = User::factory()->create();

    $issued = $this->actingAs($user)->postJson('/api/v2/auth/device-sessions', [
        'device_name' => 'Bey iPhone',
        'platform' => 'ios',
        'device_identifier' => 'vendor-id-1',
    ])->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('device_session.device_name', 'Bey iPhone')
        ->assertJsonPath('device_session.platform', 'ios')
        ->assertJsonStructure([
            'access_token',
            'refresh_token',
            'access_token_expires_at',
            'refresh_token_expires_at',
            'device_session' => ['id'],
        ]);

    $refreshToken = $issued->json('refresh_token');
    $sessionId = $issued->json('device_session.id');

    expect(DeviceSession::query()->where('public_id', $sessionId)->exists())->toBeTrue()
        ->and(DB::table('device_session_refresh_tokens')->where('token_hash', $refreshToken)->exists())->toBeFalse()
        ->and(DeviceSessionRefreshToken::query()->value('token_hash'))->toBe(hash('sha256', $refreshToken));

    $rotated = $this->postJson('/api/v2/auth/device-sessions/refresh', [
        'refresh_token' => $refreshToken,
    ])->assertOk();

    expect($rotated->json('refresh_token'))->not->toBe($refreshToken)
        ->and($rotated->json('device_session.id'))->toBe($sessionId);

    $this->postJson('/api/v2/auth/device-sessions/refresh', [
        'refresh_token' => $refreshToken,
    ])->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_refresh_token');
});

it('registers and revokes push delivery through an owned device session', function () {
    $user = User::factory()->create();
    $pair = app(DeviceSessionTokenService::class)->issue($user, 'Pixel', 'android');
    $url = "/api/v2/auth/device-sessions/{$pair->deviceSession->public_id}/push-registrations";
    $payload = [
        'provider' => 'fcm',
        'device_token' => 'private-fcm-token',
        'environment' => 'production',
    ];
    $headers = ['Idempotency-Key' => 'push-registration-1'];

    $this->actingAs($user)->postJson($url, $payload, $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false')
        ->assertJsonPath('data.provider', 'fcm');
    $this->actingAs($user)->postJson($url, $payload, $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    $registration = DevicePushRegistration::query()->sole();
    expect($registration->device_token)->toBe('private-fcm-token')
        ->and($registration->getRawOriginal('device_token'))->not->toBe('private-fcm-token');

    $this->actingAs($user)->deleteJson($url.'/fcm', [], ['Idempotency-Key' => 'push-revoke-1'])
        ->assertNoContent()
        ->assertHeader('Idempotency-Replayed', 'false');
    $this->actingAs($user)->deleteJson($url.'/fcm', [], ['Idempotency-Key' => 'push-revoke-1'])
        ->assertNoContent()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($registration->fresh()->revoked_at)->not->toBeNull();
});

it('revokes the current device token family on logout and every family on logout all', function () {
    $service = app(DeviceSessionTokenService::class);
    $user = User::factory()->create();
    $current = $service->issue($user, 'Current iPhone', 'ios');

    $this->withToken($current->accessToken)
        ->postJson('/api/v2/auth/logout')
        ->assertNoContent();

    expect($current->deviceSession->refresh()->revocation_reason)->toBe('logout')
        ->and(PersonalAccessToken::query()->find($current->deviceSession->access_token_id))->toBeNull();

    $first = $service->issue($user, 'Tablet', 'android');
    $second = $service->issue($user, 'New iPhone', 'ios');

    $this->actingAs($user)->postJson('/api/v2/auth/logout-all')->assertNoContent();

    expect($first->deviceSession->refresh()->revocation_reason)->toBe('logout_all')
        ->and($second->deviceSession->refresh()->revocation_reason)->toBe('logout_all')
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->id)->exists())->toBeFalse();
});
