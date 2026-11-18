<?php

use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\AI\SportsAiContentService;
use App\Services\Validation\ValidationReviewSummaryService;

it('limits impacts and recommended actions to active findings', function () {
    $run = new ValidationRun(['scope' => 'sport:cfb', 'status' => 'failing', 'completed_at' => now()]);
    $findings = collect([
        new ValidationFinding(['sport' => 'cfb', 'check_type' => 'validation_player_prop_freshness', 'status' => 'passing', 'message' => 'Fresh props', 'recommended_action' => 'cfb:sync-player-props']),
        new ValidationFinding(['sport' => 'cfb', 'check_type' => 'validation_finalized_data_completeness', 'status' => 'passing', 'message' => 'Complete finals', 'recommended_action' => 'espn:sync-cfb-game-details']),
        new ValidationFinding(['sport' => 'cfb', 'check_type' => 'validation_odds_completeness', 'status' => 'failing', 'message' => 'Stale quotes', 'recommended_action' => 'cfb:sync-odds']),
    ]);
    $summary = app(ValidationReviewSummaryService::class)->buildFallbackSummary($findings, $run);
    expect($summary['recommended_actions'])->toBe(['cfb:sync-odds'])
        ->and($summary['blocked_outputs'])->toBe(['Official bet classifications that require fresh odds.'])
        ->and($summary['data_schedule_today'])->toBe([])
        ->and($summary)->not->toHaveKey('trust_score')
        ->and($summary['latest_data_fresh_at'])->toContain('completion does not establish data freshness')
        ->and(implode(' ', $summary['highlights']))->not->toContain('Fresh props', 'Complete finals');
});

it('does not infer actions or blocks from passing checks', function () {
    $summary = app(ValidationReviewSummaryService::class)->buildFallbackSummary(collect([
        new ValidationFinding(['sport' => 'nba', 'check_type' => 'validation_odds_completeness', 'status' => 'passing', 'message' => 'Fresh', 'recommended_action' => 'nba:sync-odds']),
    ]), new ValidationRun(['status' => 'passing', 'completed_at' => now()]));
    expect($summary['recommended_actions'])->toBe([])
        ->and($summary['safe_adjustments'])->toBe([])
        ->and($summary['blocked_outputs'])->toBe([])
        ->and($summary['operational_status'])->toBe('healthy');
});

it('allows ai narrative without allowing ai to replace operational evidence', function () {
    $run = ValidationRun::query()->create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'passing', 'summary' => [], 'started_at' => now(), 'completed_at' => now()]);
    $run->findings()->create(['sport' => 'nba', 'check_type' => 'validation_odds_completeness', 'status' => 'passing', 'severity' => 'passing', 'message' => 'Fresh', 'detected_at' => now()]);
    $ai = Mockery::mock(SportsAiContentService::class);
    $ai->shouldReceive('generateValidationReviewSummary')->once()->andReturn([
        'headline' => 'Readable summary', 'intro' => 'Narrative only.', 'generated_by' => 'openai:test',
        'trust_score' => 1, 'operational_status' => 'critical', 'blocked_outputs' => ['Invented block'], 'recommended_actions' => ['invented:command'],
    ]);
    $summary = (new ValidationReviewSummaryService($ai))->summarizeRun($run);
    expect($summary['headline'])->toBe('Readable summary')
        ->and($summary['generated_by'])->toBe('openai:test')
        ->and($summary['operational_status'])->toBe('healthy')
        ->and($summary['blocked_outputs'])->toBe([])
        ->and($summary['recommended_actions'])->toBe([])
        ->and($summary)->not->toHaveKey('trust_score');
});
