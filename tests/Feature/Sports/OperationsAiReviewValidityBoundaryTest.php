<?php

use App\Services\Validation\ValidationRunRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-06-06 12:00:00');
    Http::preventStrayRequests();
    Notification::fake();
});

afterEach(fn () => $this->travelBack());

function aiReviewPassingFinding(): array
{
    return ['check_type' => 'validation_game_coverage', 'status' => 'passing',
        'message' => 'Synthetic completed local check.', 'metadata' => []];
}

function callActualOperationsReview(): array
{
    $exit = Artisan::call('operations:ai-review', ['--sport' => 'nba', '--season' => '2026',
        '--date' => '2026-06-06', '--json' => true]);
    expect($exit)->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('does not present unfinished or failed actual validation checks as safe to publish', function (string $state) {
    $recorder = app(ValidationRunRecorder::class);
    $run = $recorder->begin('sport:nba', 'full', skipAlerts: true);
    if ($state === 'partial_failure') {
        $recorder->record($run, 'nba', aiReviewPassingFinding());
    }
    if ($state !== 'running') {
        $recorder->complete($run, new RuntimeException('Synthetic check execution stopped'));
    }
    $run = $run->fresh();
    expect($recorder->checksCompleted($run))->toBeFalse();
    $rawBefore = $run->getRawOriginal();
    $report = callActualOperationsReview();
    fwrite(STDERR, json_encode(['fixture' => $state, 'stored_status' => $run->status,
        'execution_status' => $run->summary['execution_status'], 'review_status' => $report['status'],
        'safe_to_publish' => $report['safe_to_publish']]).PHP_EOL);
    expect($report['latest_validation']['run_id'])->toBe($run->id)
        ->and($report['safe_to_publish'])->toBeFalse()
        ->and($report['status'])->toBe('blocked')->and($report['trust_score'])->toBe(0)
        ->and($report['latest_validation']['checks_completed'])->toBeFalse()
        ->and($report['recommended_actions'])->toContain('Complete healthcheck:validate-data --sport=nba before relying on publishing readiness.')
        ->and($run->fresh()->getRawOriginal())->toBe($rawBefore);
    Http::assertNothingSent();
    Notification::assertNothingSent();
})->with(['running', 'failed_before_findings', 'partial_failure']);

it('retains safe operational reporting for completed actual checks including optional summary failure', function (bool $summaryFailed) {
    $recorder = app(ValidationRunRecorder::class);
    $run = $recorder->begin('sport:nba', 'full', skipAlerts: true);
    $recorder->record($run, 'nba', aiReviewPassingFinding());
    $recorder->complete($run);
    if ($summaryFailed) {
        $recorder->recordPostProcessingError($run, 'summary', new RuntimeException('Synthetic optional summary unavailable'));
    }
    $run = $run->fresh();
    expect($recorder->checksCompleted($run))->toBeTrue();
    $rawBefore = $run->getRawOriginal();
    $report = callActualOperationsReview();
    expect($report['latest_validation']['run_id'])->toBe($run->id)->and($report['safe_to_publish'])->toBeTrue()
        ->and($report['latest_validation']['checks_completed'])->toBeTrue()
        ->and($report['status'])->toBe('clear')->and($run->fresh()->getRawOriginal())->toBe($rawBefore);
    Http::assertNothingSent();
    Notification::assertNothingSent();
})->with(['checks_completed' => false, 'optional_summary_failed' => true]);
