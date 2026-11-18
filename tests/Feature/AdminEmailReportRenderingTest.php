<?php

use App\Mail\AdminDailyEmailReportMail;

function adminReportRenderingPayload(): array
{
    return [
        'date_label' => 'Nov 16, 2026',
        'observed_at' => '2026-11-17T07:30:00-06:00',
        'window' => ['start' => '2026-11-16T00:00:00-06:00', 'end' => '2026-11-17T00:00:00-06:00'],
        'features' => ['daily_digest_enabled' => false, 'digest_email_feature_enabled' => true, 'admin_report_enabled' => true, 'regression_alerts_enabled' => true],
        'digests' => ['sent' => 0], 'alerts' => ['sent' => 0],
        'delivery_activity' => [['type' => 'admin_report', 'channel' => 'mail', 'status' => 'accepted', 'count' => 1]],
        'delivery_backlog' => [['type' => 'daily_digest', 'channel' => 'mail', 'status' => 'sending', 'count' => 2]],
        'queue' => ['pending_mail_jobs' => 0, 'failed_mail_jobs_today' => 0, 'failed_jobs_total' => 2763],
        'deployment' => ['status' => 'unavailable', 'note' => 'Deployment metadata is not recorded; release correlation unavailable.'],
        'operations' => [
            'headline' => 'NBA needs investigation; CFB recovered', 'status' => 'attention',
            'actions' => array_map(fn (int $number): array => [
                'sport' => 'nba', 'scope' => 'full', 'message' => 'Investigate finding '.$number,
                'action' => 'nba:sync-odds', 'url' => url('/admin/healthchecks?validation_run=1055'),
            ], [1, 2, 3, 4]),
            'sports' => [[
                'sport' => 'nba', 'scope' => 'full', 'status' => 'failing', 'run_id' => 1055,
                'age_minutes' => 35, 'url' => url('/admin/healthchecks?validation_run=1055'),
                'baseline_kind' => 'yesterday',
                'findings' => [['check_type' => 'validation_odds_completeness', 'metrics' => ['games_missing_odds' => 3]]],
                'changes' => ['new' => [['check_type' => 'odds']], 'persistent' => [[]], 'recovered' => [[]]],
            ], [
                'sport' => 'cfb', 'scope' => 'data', 'status' => 'passing', 'run_id' => 1053,
                'age_minutes' => 30, 'url' => url('/admin/healthchecks?validation_run=1053'),
                'baseline_kind' => 'previous_completed', 'changes' => ['recovered' => [[], []]],
            ]],
            'evidence_gaps' => ['WCBB comparison is unavailable.'],
            'inactivity' => ['WNBA has no scheduled events in its operational window.'],
        ],
        'ai_publishing' => [],
    ];
}

it('renders a concise prioritized admin report with bounded actions and direct evidence links', function () {
    $report = adminReportRenderingPayload();
    $mail = new AdminDailyEmailReportMail($report);
    $html = $mail->render();

    foreach (['NBA needs investigation; CFB recovered', 'Nov 16, 2026', 'Digest delivery disabled', '1 new; 0 worsened; 1 ongoing; 1 recovered', '2 recovered', 'yesterday baseline', 'games missing odds: 3', 'previous completed baseline', 'WCBB comparison is unavailable.', 'release correlation unavailable.', 'Transport acceptance does not confirm inbox delivery.'] as $text) {
        $mail->assertSeeInHtml($text);
    }
    $mail->assertDontSeeInHtml('Investigate finding 4');
    $mail->assertDontSeeInHtml('trust');
    $mail->assertDontSeeInHtml('Blocked / Caveated Outputs');
    $mail->assertSeeInText('Investigate finding 1');
    $mail->assertSeeInText('nba:sync-odds');
    $mail->assertSeeInText('**Current unresolved mail:** 2');
    $mail->assertSeeInHtml('Mail dispatched');
    expect($html)->toContain('href="'.e(url('/admin/healthchecks?validation_run=1055')).'"', '<table');
    expect(strpos($html, 'Review first'))->toBeLessThan(strpos($html, 'Activity —'));
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//th[contains(., "Sport / scope")]/ancestor::table[1]//td')->length)->toBe(6);
});

it('keeps unavailable operational and queue evidence distinct from healthy or empty', function () {
    $report = adminReportRenderingPayload();
    unset($report['operations']);
    $report['queue_snapshot'] = ['queue' => ['available' => false], 'failures' => ['available' => false]];
    $mail = new AdminDailyEmailReportMail($report);

    $mail->assertSeeInHtml('Operational assessment unavailable');
    $mail->assertSeeInHtml('This does not establish an empty queue.');
    $mail->assertSeeInHtml('Failed-job evidence unavailable.');
    $mail->assertDontSeeInHtml('healthy');
});

it('shows current backlog age and safely bounded failure categories separately from period activity', function () {
    $report = adminReportRenderingPayload();
    $report['queue_snapshot'] = [
        'queue' => ['available' => true, 'totals' => ['waiting_count' => 2, 'delayed_count' => 3, 'reserved_count' => 1, 'oldest_waiting_age_seconds' => 600]],
        'failures' => ['available' => true, 'period_count' => 4, 'retained_total' => 2763,
            'top_categories' => [['category' => 'Timeout', 'count' => 4, 'latest_failure_at' => '2026-11-16 22:00:00']],
            'sampled_count' => 500, 'sample_truncated' => true],
    ];
    $mail = new AdminDailyEmailReportMail($report);

    foreach (['Oldest ready job: 10 minutes old.', 'Timeout: 4', 'latest 500 retained failures (not activity-window totals)', 'Failure categories cover a bounded sample', 'Queue reservations and retained failures do not establish worker health'] as $text) {
        $mail->assertSeeInHtml($text);
    }
});
