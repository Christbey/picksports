<?php

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\Native\NativeCredentialPolicy;
use App\Services\Auth\Native\NativeDeviceBootstrapAdmission;
use App\Services\Auth\TokenAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    config(['native_auth.epoch_issuance_enabled' => true]);
    Http::preventStrayRequests();
});

it('refuses a persisted write-only bearer from minting a read-write independent native credential family', function () {
    $parent = User::factory()->create()->createToken('arbitrary-server-name', ['mobile:write']);
    $before = PersonalAccessToken::count();
    Auth::forgetGuards();
    $this->withToken($parent->plainTextToken)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Synthetic', 'platform' => 'ios'])->assertForbidden();
    expect(DeviceSession::count())->toBe(0)->and(PersonalAccessToken::count())->toBe($before);
});

it('refuses a real native bearer from escaping its existing refresh family by bootstrapping another', function () {
    $parent = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios');
    $before = PersonalAccessToken::count();
    Auth::forgetGuards();
    $this->withToken($parent->accessToken)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Renamed', 'platform' => 'android'])->assertForbidden();
    expect(DeviceSession::count())->toBe(1)->and(PersonalAccessToken::count())->toBe($before);
});

it('accepts browser-session bootstrap under epoch admission without inferring token provenance from names', function () {
    $this->actingAs(User::factory()->create())->postJson('/api/v2/auth/device-sessions', ['device_name' => 'native:arbitrary', 'platform' => 'ios'])->assertCreated();
    expect(DeviceSession::query()->sole()->native_policy_version)->toBe(NativeCredentialPolicy::VERSION);
});

it('accepts actual finite interactive login provenance and rejects an expired parent before issuing a family', function () {
    config(['api_tokens.finite_issuance_enabled' => true, 'api_tokens.ttl_minutes' => 60]);
    $user = User::factory()->create();
    $response = $this->postJson('/api/v2/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'native:misleading-name'])->assertOk();
    $plain = $response->json('access_token');
    Auth::forgetGuards();
    $this->withToken($plain)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Synthetic', 'platform' => 'ios'])->assertCreated();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($response->json('expires_epoch')));
    Auth::forgetGuards();
    $this->withToken($plain)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Expired', 'platform' => 'ios'])->assertUnauthorized();
    expect(DeviceSession::count())->toBe(1);
    $this->travelBack();
});

it('retains unlinked legacy bearer compatibility only while epoch admission is off', function () {
    config(['native_auth.epoch_issuance_enabled' => false]);
    $user = User::factory()->create();
    $parent = $user->createToken('arbitrary', ['*']);
    Auth::forgetGuards();
    $this->withToken($parent->plainTextToken)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Synthetic', 'platform' => 'ios'])->assertCreated();
    config(['native_auth.epoch_issuance_enabled' => true]);
    Auth::forgetGuards();
    $this->withToken($parent->plainTextToken)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Unmarked', 'platform' => 'ios'])->assertForbidden();
    expect(DeviceSession::count())->toBe(1);
});

it('always refuses legacy native family bootstrap even when epoch admission is off', function () {
    config(['native_auth.epoch_issuance_enabled' => false]);
    $parent = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios');
    Auth::forgetGuards();
    $this->withToken($parent->accessToken)->postJson('/api/v2/auth/device-sessions', ['device_name' => 'Renamed', 'platform' => 'ios'])->assertForbidden();
    expect(DeviceSession::count())->toBe(1);
});

it('rechecks persisted interactive parent ownership and expiry before the issuance callback', function (string $change) {
    config(['api_tokens.finite_issuance_enabled' => true]);
    $user = User::factory()->create();
    $plain = app(TokenAuthService::class)->issueAccessToken($user, 'Synthetic');
    $token = PersonalAccessToken::findToken($plain);
    $user->withAccessToken($token);
    $request = Request::create('/api/v2/auth/device-sessions', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plain]);
    $request->setUserResolver(fn () => $user);
    DB::table('personal_access_tokens')->where('id', $token->id)->update(match ($change) {
        'owner' => ['tokenable_id' => User::factory()->create()->id],
        'expiry' => ['api_expires_epoch' => now()->getTimestamp() - 1],
        'hash' => ['token' => hash('sha256', 'synthetic-replaced-secret')],
    }); // Explicit trusted synthetic concurrent state change.
    expect(fn () => app(NativeDeviceBootstrapAdmission::class)->issue($request,
        fn () => throw new RuntimeException('Issuance must not run')))->toThrow(AccessDeniedHttpException::class);
    expect(DeviceSession::count())->toBe(0);
})->with(['owner', 'expiry', 'hash']);
