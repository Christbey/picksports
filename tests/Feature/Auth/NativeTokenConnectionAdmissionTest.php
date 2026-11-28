<?php

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use Laravel\Sanctum\PersonalAccessToken;

it('refuses mixed connection revocation before changing the existing token family', function (string $method) {
    $user = User::factory()->create();
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue($user, 'synthetic device', 'ios');
    config(['database.connections.native_connection_boundary' => config('database.connections.sqlite')]);
    $other = $user->fresh()->setConnection('native_connection_boundary');
    expect(fn () => match ($method) {
        'revoke' => $service->revoke($other, $pair->deviceSession->public_id),
        'revokeCurrent' => $service->revokeCurrent($other),
        'revokeAll' => $service->revokeAll($other),
    })->toThrow(LogicException::class, 'Native token lifecycle requires one default database connection.');
    expect($pair->deviceSession->fresh()->revoked_at)->toBeNull()
        ->and($pair->deviceSession->refreshTokens()->whereNull('revoked_at')->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(1)
        ->and(DeviceSession::query()->count())->toBe(1);
})->with(['revoke', 'revokeCurrent', 'revokeAll']);
