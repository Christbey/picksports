<?php

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Native\DevicePushRegistrationService;
use App\Services\Auth\Native\DeviceSessionTokenService;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

it('refuses a stale session object after its token family has been revoked', function () {
    $session = DeviceSession::factory()->create();
    app(DeviceSessionTokenService::class)->revoke($session->user, $session->public_id);

    expect(fn () => app(DevicePushRegistrationService::class)->register($session, 'apns', 'private-token'))
        ->toThrow(ValidationException::class);
    expect(DevicePushRegistration::query()->count())->toBe(0);
});

it('does not move another accounts push token to a new session', function () {
    $original = DeviceSession::factory()->create();
    $other = DeviceSession::factory()->create();
    $service = app(DevicePushRegistrationService::class);
    $registration = $service->register($original, 'fcm', 'existing-private-token');

    expect(fn () => $service->register($other, 'fcm', 'existing-private-token'))
        ->toThrow(ValidationException::class);
    expect($registration->fresh()->device_session_id)->toBe($original->id)
        ->and(DevicePushRegistration::query()->count())->toBe(1);
});

it('allows the same account to move its registration between active device sessions', function () {
    $original = DeviceSession::factory()->create();
    $replacement = DeviceSession::factory()->create(['user_id' => $original->user_id]);
    $service = app(DevicePushRegistrationService::class);
    $registration = $service->register($original, 'apns', 'same-device-token');
    $moved = $service->register($replacement, 'apns', 'same-device-token');

    expect($moved->id)->toBe($registration->id)
        ->and($moved->device_session_id)->toBe($replacement->id)
        ->and(DevicePushRegistration::query()->count())->toBe(1);
});

it('rejects a differently connected user before issuing any native tokens', function () {
    config(['database.connections.native_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $user = User::factory()->create()->setConnection('native_other');

    expect(fn () => app(DeviceSessionTokenService::class)->issue($user, 'device', 'ios'))
        ->toThrow(LogicException::class);
    expect(DeviceSession::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});
