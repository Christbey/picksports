<?php

use App\Models\ValidationRun;
use App\Services\Validation\AdminOperationalAssessment;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00', 'America/Chicago'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function operationalAssessmentRun(string $sport, string $scope, string $at, array $findings, string $status = 'failing', array $summary = []): ValidationRun
{
    $run = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data', 'scope' => 'sport:'.$sport, 'status' => $status,
        'summary' => ['validation_scope' => $scope, 'checks_execution_status' => 'completed', ...$summary],
        'started_at' => Carbon::parse($at)->subMinute(), 'completed_at' => Carbon::parse($at),
    ]);
    foreach ($findings as $check => $finding) {
        $run->findings()->create([
            'sport' => $sport, 'check_type' => $check, 'status' => $finding['status'], 'severity' => $finding['status'],
            'message' => $check.' observed', 'facts' => $finding['facts'] ?? [],
            'recommended_action' => $finding['action'] ?? null, 'detected_at' => $run->completed_at,
        ]);
    }

    return $run;
}

it('compares current check identities with yesterday rather than the immediate retry', function () {
    $yesterday = operationalAssessmentRun('nba', 'full', '2026-11-17 07:00:00', [
        'odds' => ['status' => 'warning'], 'stats' => ['status' => 'failing'],
        'coverage' => ['status' => 'passing'], 'recovery' => ['status' => 'failing'], 'absent_now' => ['status' => 'failing'],
        'count_growth' => ['status' => 'failing', 'facts' => ['problem_games' => 1]],
    ]);
    operationalAssessmentRun('nba', 'full', '2026-11-18 06:00:00', ['unrelated_retry_check' => ['status' => 'passing']], 'passing');
    $current = operationalAssessmentRun('nba', 'full', '2026-11-18 07:00:00', [
        'odds' => ['status' => 'failing', 'facts' => ['games_with_stale_odds' => 4], 'action' => 'nba:sync-odds'],
        'stats' => ['status' => 'failing'], 'coverage' => ['status' => 'warning'], 'recovery' => ['status' => 'passing'],
        'count_growth' => ['status' => 'failing', 'facts' => ['problem_games' => 2]],
    ]);
    $result = app(AdminOperationalAssessment::class)->build();
    $nba = collect($result['sports'])->first(fn ($entry) => $entry['sport'] === 'nba' && $entry['scope'] === 'full');
    expect($nba['run_id'])->toBe($current->id)->and($nba['baseline_run_id'])->toBe($yesterday->id)
        ->and($nba['baseline_kind'])->toBe('yesterday')
        ->and(array_column($nba['changes']['new'], 'check_type'))->toBe(['coverage'])
        ->and(array_column($nba['changes']['worsened'], 'check_type'))->toBe(['odds', 'count_growth'])
        ->and(array_column($nba['changes']['persistent'], 'check_type'))->toBe(['stats'])
        ->and(array_column($nba['changes']['recovered'], 'check_type'))->toBe(['recovery'])
        ->and(array_column($nba['changes']['unobserved'], 'check_type'))->toBe(['absent_now'])
        ->and($nba['age_minutes'])->toBe(60)
        ->and($result['status'])->toBe('attention')
        ->and($result['actions'][0]['action'])->toBe('nba:sync-odds')
        ->and($result['actions'][0]['remediation_state'])->toBe('suggested')
        ->and($result['actions'][0]['url'])->toContain('view=validation', 'sport=nba', 'validation_run='.$current->id);
});

it('labels fallback comparisons and leaves changes unknown when no baseline exists', function () {
    operationalAssessmentRun('cfb', 'full', '2026-11-18 06:00:00', ['odds' => ['status' => 'passing']], 'passing');
    operationalAssessmentRun('cfb', 'full', '2026-11-18 07:00:00', ['odds' => ['status' => 'failing']]);
    operationalAssessmentRun('wcbb', 'full', '2026-11-18 07:00:00', ['stats' => ['status' => 'failing', 'facts' => ['problem_games' => 3, 'users' => 400, 'total_affected' => 900, 'games_missing_odds' => -3]]]);
    $sports = collect(app(AdminOperationalAssessment::class)->build()['sports']);
    $cfb = $sports->first(fn ($entry) => $entry['sport'] === 'cfb' && $entry['scope'] === 'full');
    $wcbb = $sports->first(fn ($entry) => $entry['sport'] === 'wcbb' && $entry['scope'] === 'full');
    expect($cfb['baseline_kind'])->toBe('previous_completed')
        ->and($wcbb['baseline_kind'])->toBe('unavailable')
        ->and($wcbb['changes']['new'])->toBe([])
        ->and($wcbb['findings'][0]['metrics'])->toBe(['problem_games' => 3])
        ->and($wcbb['impact_note'])->toContain('affected users are unknown')
        ->and($wcbb['evidence_gaps'])->not->toBeEmpty();
});

it('preserves incomplete attempt evidence and separates full and data scope baselines', function () {
    $full = operationalAssessmentRun('nba', 'full', '2026-11-18 06:00:00', ['odds' => ['status' => 'passing']], 'passing', ['execution_status' => 'failed', 'post_processing_errors' => ['summary' => 'RuntimeException']]);
    $data = operationalAssessmentRun('nba', 'data', '2026-11-18 06:30:00', ['stats' => ['status' => 'warning']], 'warning');
    $attempt = ValidationRun::query()->create([
        'command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'interrupted',
        'started_at' => now()->subMinutes(30), 'completed_at' => now()->subMinutes(20),
        'summary' => ['validation_scope' => 'full', 'checks_execution_status' => 'failed'],
    ]);
    operationalAssessmentRun('nba', 'full', '2026-11-19 06:00:00', ['future' => ['status' => 'failing']]);
    $sports = collect(app(AdminOperationalAssessment::class)->build()['sports']);
    $nba = $sports->first(fn ($entry) => $entry['sport'] === 'nba' && $entry['scope'] === 'full');
    expect($nba['run_id'])->toBe($full->id)
        ->and($nba['latest_attempt']['id'])->toBe($attempt->id)
        ->and($nba['latest_attempt']['checks_execution_status'])->toBe('failed')
        ->and(implode(' ', $nba['evidence_gaps']))->toContain('latest attempted validation', 'optional summary')
        ->and($sports->first(fn ($entry) => $entry['sport'] === 'nba' && $entry['scope'] === 'data')['run_id'])->toBe($data->id);
});

it('reports offseason expectations without masking actual failures and limits deduplicated actions to three', function () {
    config()->set('healthchecks.season_months.wnba', [5, 6]);
    foreach (['full', 'data'] as $scope) {
        operationalAssessmentRun('wnba', $scope, '2026-11-18 07:00:00', [
            'odds' => ['status' => 'failing', 'action' => 'wnba:sync-odds'],
            'stats' => ['status' => 'failing', 'action' => 'espn:sync-wnba-game-details'],
            'coverage' => ['status' => 'warning', 'action' => 'wnba:generate-canonical-predictions'],
            'extra' => ['status' => 'warning', 'action' => 'other:investigate'],
        ]);
    }
    $result = app(AdminOperationalAssessment::class)->build();
    expect($result['inactivity'])->toContain('WNBA: seasonal jobs may be inactive under configured month guards; existing findings remain visible.')
        ->and($result['status'])->toBe('attention')
        ->and($result['actions'])->toHaveCount(3)
        ->and(array_unique(array_column($result['actions'], 'action')))->toHaveCount(3);
});

it('does not call stale or incomplete active-season check evidence healthy', function () {
    config()->set('healthchecks.season_months', ['nba' => [11]]);
    config()->set('alerts.admin_report.validation_max_age_minutes', 60);
    operationalAssessmentRun('nba', 'full', '2026-11-18 06:00:00', ['odds' => ['status' => 'passing']], 'passing');
    $result = app(AdminOperationalAssessment::class)->build();
    $nba = collect($result['sports'])->first(fn ($entry) => $entry['sport'] === 'nba' && $entry['scope'] === 'full');
    expect($result['status'])->toBe('unknown')
        ->and($nba['status'])->toBe('passing')
        ->and($nba['evidence_incomplete'])->toBeTrue()
        ->and(implode(' ', $nba['evidence_gaps']))->toContain('configured report age limit', 'independently of source data freshness');
});

it('does not promote a completed run without check records to verified healthy coverage', function () {
    config()->set('healthchecks.season_months', ['nba' => [11]]);
    operationalAssessmentRun('nba', 'full', '2026-11-18 07:00:00', [], 'passing');
    $result = app(AdminOperationalAssessment::class)->build();
    $nba = collect($result['sports'])->first(fn ($entry) => $entry['sport'] === 'nba' && $entry['scope'] === 'full');
    expect($result['status'])->toBe('unknown')
        ->and($nba['evidence_incomplete'])->toBeTrue()
        ->and(implode(' ', $nba['evidence_gaps']))->toContain('contains no finding records');
});
