<?php

use App\Mail\AdminDailyEmailReportMail;
use App\Models\DailyDigestSend;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Models\ValidationRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-30 08:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('admin email report sends operational report to admin users', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create(['email' => 'admin@picksports.app']);
    $user = User::factory()->create();

    DailyDigestSend::query()->create([
        'user_id' => $user->id,
        'digest_date' => '2026-05-30',
        'sent_at' => now(),
        'predictions_count' => 2,
        'player_props_count' => 1,
    ]);

    UserAlertSent::query()->create([
        'user_id' => $user->id,
        'sport' => 'mlb',
        'alert_type' => 'betting_value',
        'expected_value' => 7.5,
        'sent_at' => now(),
    ]);

    ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => ['failing' => 0, 'warning' => 2, 'passing' => 12],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->artisan('alerts:send-admin-email-report')
        ->expectsOutput('Sent admin email report to 1 recipient(s).')
        ->assertSuccessful();

    Mail::assertSent(AdminDailyEmailReportMail::class, function (AdminDailyEmailReportMail $mail) use ($admin) {
        return $mail->hasTo($admin->email)
            && $mail->report['digests']['sent'] === 1
            && $mail->report['digests']['predictions'] === 2
            && $mail->report['alerts']['sent'] === 1
            && $mail->report['alerts']['by_sport']['mlb'] === 1
            && $mail->report['validation']['status'] === 'warning';
    });
});

test('admin email report supports recipient override', function () {
    Mail::fake();

    User::factory()->admin()->create(['email' => 'admin@picksports.app']);

    $this->artisan('alerts:send-admin-email-report --to=ops@picksports.app')
        ->expectsOutput('Sent admin email report to 1 recipient(s).')
        ->assertSuccessful();

    Mail::assertSent(AdminDailyEmailReportMail::class, fn (AdminDailyEmailReportMail $mail) => $mail->hasTo('ops@picksports.app'));
    Mail::assertNotSent(AdminDailyEmailReportMail::class, fn (AdminDailyEmailReportMail $mail) => $mail->hasTo('admin@picksports.app'));
});
