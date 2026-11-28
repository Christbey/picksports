<?php

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use App\Services\Auth\Native\DevicePushRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.native_push_writer' => config('database.connections.mysql')]);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('native_push_writer');
});

it('refuses a registration when another connection revoked the session after an old snapshot', function () {
    $session = DeviceSession::factory()->create();
    DB::beginTransaction();
    DB::table('device_sessions')->where('id', $session->id)->first();
    DB::connection('native_push_writer')->table('device_sessions')->where('id', $session->id)
        ->update(['revoked_at' => now(), 'revocation_reason' => 'manual']);

    expect(fn () => app(DevicePushRegistrationService::class)->register($session, 'apns', 'private-token'))
        ->toThrow(ValidationException::class);
    DB::commit();

    expect(DevicePushRegistration::query()->count())->toBe(0);
});

it('uses the current registration owner rather than an old repeatable read snapshot', function () {
    $original = DeviceSession::factory()->create();
    $other = DeviceSession::factory()->create();
    $service = app(DevicePushRegistrationService::class);
    $registration = $service->register($original, 'fcm', 'existing-token');
    DB::beginTransaction();
    DB::table('device_push_registrations')->where('id', $registration->id)->first();
    DB::connection('native_push_writer')->table('device_push_registrations')->where('id', $registration->id)
        ->update(['device_session_id' => $other->id]);

    expect(fn () => $service->register($original, 'fcm', 'existing-token'))
        ->toThrow(ValidationException::class);
    DB::commit();

    expect($registration->fresh()->device_session_id)->toBe($other->id)
        ->and(DevicePushRegistration::query()->count())->toBe(1);
});
