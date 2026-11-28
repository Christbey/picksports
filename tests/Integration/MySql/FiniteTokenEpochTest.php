<?php

use App\Models\User;
use App\Services\Auth\TokenAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['api_tokens.finite_issuance_enabled' => true, 'api_tokens.ttl_minutes' => 60]);
});

it('enforces both API token fold epochs despite changing MySQL session zones', function (string $zone) {
    $original = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
    try {
        foreach (['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z'] as $instant) {
            DB::statement('SET time_zone = ?', [$zone]);
            $start = CarbonImmutable::parse($instant)->getTimestamp();
            $this->travelTo(CarbonImmutable::createFromTimestampUTC($start));
            $user = User::factory()->create();
            $grant = app(TokenAuthService::class)->issueGrant($user, 'fixture-mysql');
            $row = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->sole();
            expect((int) $row->api_expires_epoch)->toBe($start + 3600)->and($row->expires_at)->toBeNull();
            DB::statement('SET time_zone = ?', [$zone === '+09:00' ? '-06:00' : '+09:00']);
            $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 3599));
            expect(CarbonImmutable::now('UTC')->getTimestamp())->toBe($start + 3599);
            Auth::forgetGuards();
            $this->withToken($grant->plainTextToken)->getJson('/api/v2/auth/me')->assertOk();
            $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + 3600));
            Auth::forgetGuards();
            $this->withToken($grant->plainTextToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
        }
    } finally {
        DB::statement('SET time_zone = ?', [$original]);
    }
})->with(['+00:00', '-06:00', '+09:00']);
