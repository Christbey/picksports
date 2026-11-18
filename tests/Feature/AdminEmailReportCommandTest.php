<?php

use App\Mail\AdminDailyEmailReportMail;
use App\Models\DailyDigestSend;
use App\Models\NotificationDelivery;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Models\ValidationRun;
use App\Services\Validation\ValidationRunRecorder;
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
        'digest_date' => '2026-05-29',
        'sent_at' => now()->subDay(),
        'predictions_count' => 2,
        'player_props_count' => 1,
    ]);

    UserAlertSent::query()->create([
        'user_id' => $user->id,
        'sport' => 'mlb',
        'alert_type' => 'betting_value',
        'expected_value' => 7.5,
        'sent_at' => now()->subDay(),
    ]);

    ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data',
        'scope' => 'sport:nba',
        'status' => 'warning',
        'summary' => ['failing' => 0, 'warning' => 2, 'passing' => 12],
        'ai_summary' => [
            'headline' => 'NBA validation needs attention',
            'intro' => 'Odds need follow-up.',
            'highlights' => ['Odds coverage is incomplete.'],
            'recommended_actions' => ['nba:sync-odds'],
            'latest_data_fresh_at' => 'Not fully fresh in the latest run completed May 30, 2026 8:00 AM',
            'data_schedule_today' => ['Validation runs before the admin report.'],
            'tweak_recommendations' => ['Run odds sync closer to digest generation.'],
            'operational_status' => 'watch',
            'trust_score' => 88,
            'blocked_outputs' => ['Official bet classifications that require fresh odds.'],
            'safe_adjustments' => ['nba:sync-odds'],
            'data_quality_notes' => ['NBA odds coverage is incomplete.'],
            'generated_by' => 'template-validation-summary-v1',
        ],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    SportsAiPredictionAnalysis::query()->create([
        'sport' => 'nba',
        'game_id' => 1001,
        'prediction_id' => 2001,
        'game_date' => '2026-05-30',
        'as_of_date' => '2026-05-29',
        'market' => 'game',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_hash' => 'test-shadow-hash',
        'raw_payload' => [
            'game' => ['matchup' => 'BOS @ LAL'],
        ],
        'recommendation' => 'moneyline',
        'ai_confidence' => 63,
        'analysis_confidence' => 59,
        'bet_classification' => 'lean',
        'summary' => 'Lean Lakers moneyline.',
        'key_factors' => ['Model edge is positive.'],
        'risk_flags' => ['moderate_confidence'],
        'reason_codes' => ['model_home_edge'],
        'market_notes' => ['moneyline' => 'Playable only at a fair number.'],
        'calculated_edge' => ['spread_edge' => 0.9],
        'metadata' => [
            'shadow_agents' => [
                'data_freshness' => ['freshness_status' => 'watch'],
                'market_readiness' => ['market_status' => 'watch'],
                'model_audit' => ['model_status' => 'usable'],
                'publishing_guardrail' => [
                    'decision' => 'downgrade',
                    'publishable_classification' => 'watch',
                    'summary' => 'Props need refresh before official labeling.',
                    'required_actions' => ['nba:sync-player-props'],
                ],
            ],
        ],
        'latency_ms' => 1500,
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
            && $mail->report['validations']['nba']['full']['status'] === 'warning'
            && $mail->report['validations']['nba']['full']['assessment']['data_schedule_today'] === []
            && $mail->report['ai_publishing']['total'] === 1
            && $mail->report['ai_publishing']['enforcement']['mode'] === 'shadow'
            && $mail->report['ai_publishing']['enforcement']['enabled'] === false
            && $mail->report['ai_publishing']['decisions']['downgrade'] === 1
            && $mail->report['ai_publishing']['needs_attention'][0]['matchup'] === 'BOS @ LAL'
            && $mail->report['ai_publishing']['needs_attention'][0]['required_actions'][0] === 'nba:sync-player-props';
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

test('admin report separates completed scopes and excludes interrupted and future checks', function () {
    Mail::fake();
    config()->set('alerts.daily_digest.enabled', false);
    User::factory()->admin()->create();
    $recorder = app(ValidationRunRecorder::class);
    $full = $recorder->begin('sport:nba', 'full');
    $recorder->record($full, 'nba', ['check_type' => 'validation_odds_completeness', 'status' => 'passing', 'message' => 'Fresh']);
    $recorder->complete($full);
    $recorder->recordPostProcessingError($full, 'summary', new RuntimeException('optional summary failed'));
    $data = $recorder->begin('sport:nba', 'data');
    $recorder->record($data, 'nba', ['check_type' => 'validation_team_stat_coverage', 'status' => 'failing', 'message' => 'Missing stats']);
    $recorder->complete($data);
    ValidationRun::query()->create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'interrupted', 'summary' => [], 'started_at' => now(), 'completed_at' => now()]);
    ValidationRun::query()->create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'passing', 'summary' => [], 'started_at' => now(), 'completed_at' => now()->addHour()]);

    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();
    Mail::assertSent(AdminDailyEmailReportMail::class, function ($mail) use ($full, $data) {
        expect($mail->report['validations']['nba']['full']['id'])->toBe($full->id)
            ->and($mail->report['validations']['nba']['data']['id'])->toBe($data->id)
            ->and($mail->report['validations']['nfl']['full'])->toBeNull()
            ->and($mail->report['features']['daily_digest_enabled'])->toBeFalse()
            ->and($mail->report['features']['betting_alert_check_registered'])->toBeFalse()
            ->and($mail->report['alerts']['average_edge'])->toBeNull();
        $html = $mail->render();
        expect($html)->toContain('No completed check run is available', 'Transport acceptance does not confirm', 'no automatic check command is registered')
            ->not->toContain('/100 trust', 'Blocked / Caveated Outputs', 'Data Schedule Today');

        return true;
    });
});

test('admin report dry run has no delivery side effects and accepted reports deduplicate', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $this->artisan('alerts:send-admin-email-report --dry-run')->assertSuccessful();
    expect(NotificationDelivery::query()->count())->toBe(0);
    Mail::assertNothingSent();
    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();
    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();
    Mail::assertSentCount(1);
    expect(NotificationDelivery::query()->where('type', 'admin_report')->where('status', 'accepted')->count())->toBe(1);
});

test('admin report counts audited outcomes by their outcome time and channel without combining historical records', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $record = fn (string $key, string $channel, string $status, array $times) => NotificationDelivery::query()->create([
        'type' => 'betting_value_alert', 'recipient_key' => 'test-recipient', 'channel' => $channel,
        'deduplication_key' => $key, 'status' => $status, ...$times,
    ]);
    $record('accepted-yesterday-created-earlier', 'mail', 'accepted', ['created_at' => now()->subDays(2), 'accepted_at' => now()->subDay()]);
    $record('old-acceptance', 'mail', 'accepted', ['accepted_at' => now()->subDays(2)]);
    $record('future-acceptance', 'mail', 'accepted', ['accepted_at' => now()->addHour()]);
    $record('push-dispatch', 'database', 'dispatched', ['dispatched_at' => now()->subDay()]);
    $record('uncertain-sending', 'mail', 'sending', [])->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();
    Mail::assertSent(AdminDailyEmailReportMail::class, function ($mail) {
        expect($mail->report['delivery_activity'])->toBe([
            ['type' => 'betting_value_alert', 'channel' => 'database', 'status' => 'dispatched', 'count' => 1],
            ['type' => 'betting_value_alert', 'channel' => 'mail', 'status' => 'accepted', 'count' => 1],
        ])->and($mail->report['alerts']['sent'])->toBe(0)
            ->and(collect($mail->report['delivery_backlog'])->where('status', 'sending')->sum('count'))->toBe(1)
            ->and($mail->report['window']['end'])->toBe('2026-05-30T00:00:00-05:00');

        return true;
    });
});

test('admin report retains prior-day dispatch activity when transport accepts the message today', function () {
    Mail::fake();
    User::factory()->admin()->create();
    NotificationDelivery::query()->create([
        'type' => 'betting_value_alert', 'recipient_key' => 'test-recipient', 'channel' => 'mail',
        'deduplication_key' => 'cross-day', 'status' => 'accepted',
        'dispatched_at' => now()->subDay(), 'accepted_at' => now()->subHour(),
    ]);

    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();

    Mail::assertSent(AdminDailyEmailReportMail::class, function ($mail) {
        expect($mail->report['delivery_activity'])->toBe([
            ['type' => 'betting_value_alert', 'channel' => 'mail', 'status' => 'dispatched', 'count' => 1],
        ])->and($mail->report['delivery_backlog'])->toBe([]);

        return true;
    });
});

test('admin report defaults to the previous complete Central day across daylight saving', function () {
    $this->travelTo(Carbon::parse('2026-11-02 07:30:00', 'America/Chicago'));
    Mail::fake();
    User::factory()->admin()->create();
    $makeDelivery = fn (string $key, string $at) => NotificationDelivery::query()->create([
        'type' => 'test', 'recipient_key' => 'test', 'channel' => 'mail', 'status' => 'accepted',
        'deduplication_key' => $key, 'accepted_at' => Carbon::parse($at, 'America/Chicago'),
    ]);
    $makeDelivery('start', '2026-11-01 00:00:00');
    $makeDelivery('last-second', '2026-11-01 23:59:59');
    $makeDelivery('next-day', '2026-11-02 00:00:00');

    $this->artisan('alerts:send-admin-email-report')->assertSuccessful();

    Mail::assertSent(AdminDailyEmailReportMail::class, function ($mail) {
        expect($mail->report['date'])->toBe('2026-11-01')
            ->and($mail->report['window']['start'])->toBe('2026-11-01T00:00:00-05:00')
            ->and($mail->report['window']['end'])->toBe('2026-11-02T00:00:00-06:00')
            ->and($mail->report['window']['end_exclusive'])->toBeTrue()
            ->and($mail->report['observed_at'])->toBe('2026-11-02T07:30:00-06:00')
            ->and($mail->report['delivery_activity'])->toBe([
                ['type' => 'test', 'channel' => 'mail', 'status' => 'accepted', 'count' => 2],
            ])
            ->and($mail->report['deployment']['status'])->toBe('unavailable');

        return true;
    });
});

test('admin report rejects invalid or future activity dates without sending', function (string $date) {
    Mail::fake();
    $this->artisan('alerts:send-admin-email-report', ['--date' => $date])->assertFailed();
    Mail::assertNothingSent();
    expect(NotificationDelivery::query()->count())->toBe(0);
})->with(['2026-02-30', 'tomorrow', '2026-05-31']);
