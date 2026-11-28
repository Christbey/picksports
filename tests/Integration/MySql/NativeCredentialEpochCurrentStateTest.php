<?php

use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['native_auth.epoch_issuance_enabled' => true, 'native_auth.access_token_ttl_minutes' => 15,
        'native_auth.refresh_token_ttl_days' => 1, 'app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-01T06:55:00Z'));
    Http::preventStrayRequests();
    config(['database.connections.native_epoch_writer' => DB::connection()->getConfig()]);
    DB::purge('native_epoch_writer');
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::statement("SET time_zone = '+00:00'");
    DB::disconnect('native_epoch_writer');
    $this->travelBack();
});

it('round-trips native expiry epochs across non-UTC SQL sessions without populating legacy timestamps', function () {
    DB::statement("SET time_zone = '+09:00'");
    $start = CarbonImmutable::now('UTC')->getTimestamp();
    $service = app(DeviceSessionTokenService::class);
    $pair = $service->issue(User::factory()->create(), 'Synthetic', 'ios');
    $session = $pair->deviceSession->fresh();
    $refresh = $session->refreshTokens()->sole();
    expect($session->getRawOriginal('access_token_expires_at'))->toBeNull()
        ->and($session->access_token_expires_at->getTimestamp())->toBe($start + 900)
        ->and($refresh->getRawOriginal('expires_at'))->toBeNull()
        ->and($refresh->expires_at->getTimestamp())->toBe($start + 86400)
        ->and($session->accessToken->getRawOriginal('expires_at'))->toBeNull();
    config(['native_auth.epoch_issuance_enabled' => false]);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 60));
    $next = $service->rotate($pair->refreshToken);
    expect($next->accessTokenExpiresAt->getTimestamp())->toBe($start + 960)
        ->and($next->deviceSession->refreshTokens()->count())->toBe(2);
    Auth::forgetGuards();
    $this->withToken($next->accessToken)->getJson('/api/v2/auth/me')->assertOk();
});

it('rejects corrected native expiry or revocation despite an older repeatable-read token snapshot', function (string $change) {
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'Synthetic', 'ios');
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    $oldToken = PersonalAccessToken::findToken($pair->accessToken);
    $pair->deviceSession->fresh();
    $writer = DB::connection('native_epoch_writer');
    $writer->transaction(function () use ($writer, $pair, $change): void {
        if ($change === 'revoke') {
            $writer->table('personal_access_tokens')->where('id', $pair->deviceSession->access_token_id)->delete();
        } else {
            $writer->table('device_sessions')->where('id', $pair->deviceSession->id)->update(['access_expires_epoch' => now('UTC')->getTimestamp() - 1]);
            $writer->table('personal_access_tokens')->where('id', $pair->deviceSession->access_token_id)->update(['api_expires_epoch' => now('UTC')->getTimestamp() - 1]);
        }
    }); // Explicit trusted synthetic second-connection correction.
    expect((new ApiTokenPolicyAuthenticator)($oldToken, true))->toBeFalse();
})->with(['expiry', 'revoke']);
