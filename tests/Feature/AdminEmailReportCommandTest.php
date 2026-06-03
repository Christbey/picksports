<?php

use App\Mail\AdminDailyEmailReportMail;
use App\Models\DailyDigestSend;
use App\Models\SportsAiPredictionAnalysis;
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
        'as_of_date' => '2026-05-30',
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
            && $mail->report['validation']['status'] === 'warning'
            && $mail->report['validation']['ai_summary']['latest_data_fresh_at'] === 'Not fully fresh in the latest run completed May 30, 2026 8:00 AM'
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
