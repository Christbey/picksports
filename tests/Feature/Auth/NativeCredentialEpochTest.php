<?php

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\Native\InvalidRefreshToken;
use App\Services\Auth\Native\NativeCredentialPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

it('keeps native access epochs and HTTP admission exact through the repeated hour', function (string $instant) {
    config(['native_auth.epoch_issuance_enabled' => true, 'native_auth.access_token_ttl_minutes' => 15, 'app.timezone' => 'America/Chicago']);
    $start = CarbonImmutable::parse($instant)->getTimestamp();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start));
    expect(CarbonImmutable::now('UTC')->getTimestamp())->toBe($start);
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'epoch-fixture', 'ios');
    expect($pair->accessTokenExpiresAt->getTimestamp())->toBe($start + 900)
        ->and(DeviceSession::findOrFail($pair->deviceSession->id)->access_token_expires_at->getTimestamp())->toBe($start + 900);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 899));
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertOk();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 901));
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
})->with(['2026-11-25T18:00:00Z', '2026-11-01T06:40:00Z', '2026-11-01T06:55:00Z', '2026-11-01T07:40:00Z']);

beforeEach(function () {
    config(['native_auth.epoch_issuance_enabled' => true, 'native_auth.access_token_ttl_minutes' => 15, 'native_auth.refresh_token_ttl_days' => 1]);
});

afterEach(function () {
    $this->travelBack();
});

it('stores canonical expiry only as UTC epochs and preserves refresh reuse history across flag-off rotation', function () {
    $start = CarbonImmutable::parse('2026-11-01T06:55:00Z')->getTimestamp();
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start));
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    $session = $pair->deviceSession->fresh();
    $refresh = $session->refreshTokens()->sole();
    $access = $session->accessToken;
    expect($pair->refreshTokenExpiresAt->getTimestamp())->toBe($start + 86400)
        ->and($session->getRawOriginal('access_token_expires_at'))->toBeNull()
        ->and($refresh->getRawOriginal('expires_at'))->toBeNull()
        ->and($access->getRawOriginal('expires_at'))->toBeNull()
        ->and($session->access_expires_epoch)->toBe($start + 900)
        ->and($refresh->expires_epoch)->toBe($start + 86400)
        ->and($refresh->expires_at->getTimestamp())->toBe($start + 86400);
    config(['native_auth.epoch_issuance_enabled' => false]);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 60));
    $next = $service->rotate($pair->refreshToken);
    expect($next->accessTokenExpiresAt->getTimestamp())->toBe($start + 960)
        ->and($next->deviceSession->fresh()->native_policy_version)->toBe(NativeCredentialPolicy::VERSION)
        ->and($next->deviceSession->refreshTokens()->count())->toBe(2)
        ->and($refresh->fresh()->used_at)->not->toBeNull();
    Auth::forgetGuards();
    $this->withToken($next->accessToken)->getJson('/api/v2/auth/me')->assertOk();
    expect(fn () => $service->rotate($pair->refreshToken))->toThrow(InvalidRefreshToken::class);
    expect($next->deviceSession->fresh()->revocation_reason)->toBe('refresh_token_reuse')
        ->and($next->deviceSession->refreshTokens()->count())->toBe(2);
});

it('expires the canonical refresh at the exact epoch rather than a timezone wall clock', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01T06:40:00Z'));
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($pair->refreshTokenExpiresAt->getTimestamp()));
    expect(fn () => $service->rotate($pair->refreshToken))->toThrow(InvalidRefreshToken::class);
    expect($pair->deviceSession->fresh()->revocation_reason)->toBe('refresh_token_expired');
});

it('preserves legacy rotation until admission and upgrades only the newly issued replacement', function () {
    config(['native_auth.epoch_issuance_enabled' => false]);
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    $legacy = $pair->deviceSession->refreshTokens()->sole();
    $legacyNext = $service->rotate($pair->refreshToken);
    expect($legacyNext->deviceSession->fresh()->native_policy_version)->toBeNull();
    config(['native_auth.epoch_issuance_enabled' => true]);
    $upgraded = $service->rotate($legacyNext->refreshToken);
    expect($legacy->fresh()->native_policy_version)->toBeNull()
        ->and($legacy->fresh()->expires_at)->not->toBeNull()
        ->and($upgraded->deviceSession->fresh()->native_policy_version)->toBe(NativeCredentialPolicy::VERSION)
        ->and($upgraded->deviceSession->refreshTokens()->whereNull('used_at')->sole()->getRawOriginal('expires_at'))->toBeNull();
});

it('refuses malformed recorded session policies without rotating or deleting the access credential', function (array $changes) {
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    DB::table('device_sessions')->where('id', $pair->deviceSession->id)->update($changes); // Trusted synthetic corruption.
    expect(fn () => $service->rotate($pair->refreshToken))->toThrow(InvalidRefreshToken::class);
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
    expect($pair->deviceSession->refreshTokens()->count())->toBe(1)
        ->and(PersonalAccessToken::count())->toBe(1);
})->with([
    [['native_policy_version' => 'unknown-native']],
    [['access_expires_epoch' => null]],
    [['access_token_expires_at' => '2027-01-01 00:00:00']],
]);

it('refuses partial refresh policies without losing family history', function (array $changes) {
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    DB::table('device_session_refresh_tokens')->where('device_session_id', $pair->deviceSession->id)->update($changes); // Trusted synthetic corruption.
    expect(fn () => $service->rotate($pair->refreshToken))->toThrow(InvalidRefreshToken::class);
    expect($pair->deviceSession->refreshTokens()->count())->toBe(1)
        ->and(PersonalAccessToken::count())->toBe(1);
})->with([
    [['native_policy_version' => 'unknown-native']],
    [['expires_epoch' => null]],
    [['expires_at' => '2027-01-01 00:00:00']],
]);

it('never treats incomplete PAT markers as legacy native access', function (array $changes) {
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios');
    DB::table('personal_access_tokens')->where('id', $pair->deviceSession->access_token_id)->update($changes); // Trusted synthetic corruption.
    config(['native_auth.epoch_issuance_enabled' => false]);
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
})->with([
    [['api_policy_version' => null, 'api_token_profile' => null, 'api_expires_epoch' => null]],
    [['api_policy_version' => 'unknown-native']],
    [['api_token_profile' => 'interactive']],
    [['abilities' => '["*"]']],
]);

it('rejects invalid native TTL configuration before storing credentials', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(fn () => app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios'))->toThrow(InvalidArgumentException::class);
    expect(DeviceSession::count())->toBe(0)->and(PersonalAccessToken::count())->toBe(0);
})->with([
    ['native_auth.access_token_ttl_minutes', 0], ['native_auth.access_token_ttl_minutes', 1441],
    ['native_auth.access_token_ttl_minutes', 1.5], ['native_auth.access_token_ttl_minutes', true],
    ['native_auth.refresh_token_ttl_days', 366], ['native_auth.refresh_token_ttl_days', '1.5'],
]);

it('keeps server-owned native read scope enforced through the actual HTTP middleware', function () {
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios', abilities: ['mobile:read']);
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertOk();
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->postJson('/api/v2/auth/logout')->assertForbidden();
    expect($pair->deviceSession->fresh()->revoked_at)->toBeNull();
});
