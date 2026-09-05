<?php

use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use App\Models\User;
use App\Services\Auth\Native\DevicePushRegistrationService;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\Native\InvalidRefreshToken;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    config()->set('native_auth.access_token_ttl_minutes', 10);
    config()->set('native_auth.refresh_token_ttl_days', 7);
    config()->set('native_auth.abilities', ['mobile:read', 'mobile:write']);
});

it('issues an expiring scoped access token and stores only the refresh token hash', function () {
    $user = User::factory()->create();

    $pair = app(DeviceSessionTokenService::class)->issue(
        $user,
        'Bey iPhone',
        'ios',
        'vendor-device-id',
    );

    $session = $pair->deviceSession->refresh();
    $refresh = $session->refreshTokens()->sole();
    $access = PersonalAccessToken::query()->findOrFail($session->access_token_id);

    expect($pair->accessToken)->not->toBe('')
        ->and($pair->refreshToken)->not->toBe('')
        ->and($pair->refreshToken)->not->toBe($refresh->getRawOriginal('token_hash'))
        ->and($refresh->getRawOriginal('token_hash'))->toBe(hash('sha256', $pair->refreshToken))
        ->and($access->abilities)->toBe(['mobile:read', 'mobile:write'])
        ->and($access->expires_at?->timestamp)->toBe($pair->accessTokenExpiresAt->timestamp)
        ->and($session->device_identifier_hash)->toBe(hash('sha256', 'vendor-device-id'))
        ->and($session->token_family_id)->not->toBeNull();

    expect(DB::table('device_session_refresh_tokens')->where('token_hash', $pair->refreshToken)->exists())
        ->toBeFalse();
});

it('rotates refresh tokens once and revokes the replaced access token', function () {
    $pair = app(DeviceSessionTokenService::class)->issue(
        User::factory()->create(),
        'Pixel',
        'android',
    );
    $oldAccessTokenId = $pair->deviceSession->access_token_id;

    $rotated = app(DeviceSessionTokenService::class)->rotate($pair->refreshToken);
    $oldRefresh = DeviceSessionRefreshToken::query()
        ->where('token_hash', hash('sha256', $pair->refreshToken))
        ->sole();

    expect($rotated->refreshToken)->not->toBe($pair->refreshToken)
        ->and($oldRefresh->used_at)->not->toBeNull()
        ->and($oldRefresh->replaced_by_token_id)->not->toBeNull()
        ->and($rotated->deviceSession->access_token_id)->not->toBe($oldAccessTokenId)
        ->and(PersonalAccessToken::query()->find($oldAccessTokenId))->toBeNull()
        ->and(PersonalAccessToken::query()->find($rotated->deviceSession->access_token_id))->not->toBeNull();
});

it('detects refresh token reuse and revokes the token family and device access', function () {
    $service = app(DeviceSessionTokenService::class);
    $issued = $service->issue(User::factory()->create(), 'iPhone', 'ios');
    $rotated = $service->rotate($issued->refreshToken);
    $push = app(DevicePushRegistrationService::class)->register(
        $rotated->deviceSession,
        'apns',
        'compromised-device-token',
    );

    try {
        $service->rotate($issued->refreshToken);
        $this->fail('Expected refresh-token reuse to be rejected.');
    } catch (InvalidRefreshToken $exception) {
        expect($exception->reason)->toBe('reused');
    }

    $session = $rotated->deviceSession->refresh();

    expect($session->revoked_at)->not->toBeNull()
        ->and($session->revocation_reason)->toBe('refresh_token_reuse')
        ->and($session->access_token_id)->toBeNull()
        ->and($session->refreshTokens()->whereNull('revoked_at')->exists())->toBeFalse()
        ->and($push->fresh()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::query()->find($rotated->deviceSession->access_token_id))->toBeNull();
});

it('expires refresh tokens and supports explicit device-session revocation', function () {
    $service = app(DeviceSessionTokenService::class);
    $expired = $service->issue(User::factory()->create(), 'Old iPhone', 'ios');
    $expired->deviceSession->refreshTokens()->update(['expires_at' => now()->subSecond()]);

    try {
        $service->rotate($expired->refreshToken);
        $this->fail('Expected an expired refresh token to be rejected.');
    } catch (InvalidRefreshToken $exception) {
        expect($exception->reason)->toBe('expired');
    }

    expect($expired->deviceSession->refresh()->revocation_reason)->toBe('refresh_token_expired');

    $active = $service->issue($expired->deviceSession->user, 'New iPhone', 'ios');

    expect($service->revoke($active->deviceSession->user, $active->deviceSession->public_id))->toBeTrue()
        ->and($active->deviceSession->refresh()->revocation_reason)->toBe('manual')
        ->and($active->deviceSession->refreshTokens()->whereNull('revoked_at')->exists())->toBeFalse();
});

it('encrypts APNs and FCM registration tokens and can revoke a registration', function (string $provider) {
    $session = DeviceSession::factory()->create();
    $service = app(DevicePushRegistrationService::class);
    $plainToken = $provider.'-private-device-token';

    $registration = $service->register($session, $provider, $plainToken, 'sandbox');
    $storedToken = DB::table('device_push_registrations')
        ->where('id', $registration->getKey())
        ->value('device_token');

    expect($registration->fresh()->device_token)->toBe($plainToken)
        ->and($storedToken)->not->toBe($plainToken)
        ->and($registration->token_hash)->toBe(hash('sha256', $plainToken))
        ->and($service->revoke($session, $provider, $plainToken))->toBeTrue()
        ->and($registration->fresh()->revoked_at)->not->toBeNull();
})->with(['apns', 'fcm']);
