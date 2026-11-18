<?php

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Actions\Validation\SportValidator;
use App\Models\Healthcheck;
use App\Models\User;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Notifications\ValidationRegressionAlert;
use App\Services\Validation\ValidationRegressionAlertService;
use App\Services\Validation\ValidationReviewSummaryService;
use App\Services\Validation\ValidationRunRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-11-17 18:00:00');
    config(['ai.features.validation_review_summary.enabled' => false]);
});
afterEach(fn () => $this->travelBack());

function healthLifecycleResult(string $status = 'failing'): array
{
    return ['check_type' => 'validation_fixture', 'status' => $status, 'message' => 'Recorded data finding.'];
}

function healthLifecycleOptions(array $extra = []): array
{
    return [...['--sport' => 'nba', '--skip-ai-summary' => true, '--skip-alerts' => true], ...$extra];
}

it('preserves failing findings in reporting mode without reporting an execution failure', function () {
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldReceive('results')->once()->andReturn([healthLifecycleResult()]));
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--report-only' => true]))->assertSuccessful();
    $run = ValidationRun::latest('id')->first();
    expect($run->status)->toBe('failing')->and($run->summary['execution_status'])->toBe('completed')
        ->and($run->findings()->first()->status)->toBe('failing')
        ->and(Healthcheck::latest('id')->first()->status)->toBe('failing');
});

it('does not reuse a data-only audit for a full validation or an execution failure for any validation', function () {
    $recorder = app(ValidationRunRecorder::class);
    $data = $recorder->begin('sport:nba', 'data');
    $recorder->complete($data);
    expect($recorder->recent('sport:nba', 'full', 120))->toBeNull()
        ->and($recorder->recent('sport:nba', 'data', 120)?->id)->toBe($data->id);
    $full = $recorder->begin('sport:nba', 'full');
    $recorder->complete($full, new RuntimeException('Interrupted'));
    expect($recorder->recent('sport:nba', 'full', 120))->toBeNull();
});

it('keeps a cached failing audit failing for strict callers but allows reporting callers to reuse it', function () {
    $recorder = app(ValidationRunRecorder::class);
    $run = $recorder->begin('sport:nba', 'full');
    $recorder->record($run, 'nba', healthLifecycleResult());
    $recorder->complete($run);
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldNotReceive('results'));
    $options = healthLifecycleOptions(['--if-stale' => 120]);
    $this->artisan('healthcheck:validate-data', $options)->assertFailed();
    $this->artisan('healthcheck:validate-data', [...$options, '--report-only' => true])->assertSuccessful();
    expect(ValidationRun::count())->toBe(1);
});

it('preserves partial findings and finalizes the run when the result stream is interrupted', function () {
    $stream = (function () {
        yield healthLifecycleResult('passing');
        throw new RuntimeException('Result stream interrupted');
    })();
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldReceive('results')->once()->andReturn($stream));
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--report-only' => true]))->assertFailed();
    $run = ValidationRun::latest('id')->first();
    expect($run->status)->toBe('failing')->and($run->completed_at)->not->toBeNull()
        ->and($run->summary['execution_status'])->toBe('failed')->and($run->summary['total_findings'])->toBe(1)
        ->and($run->findings()->count())->toBe(1)->and(Healthcheck::count())->toBe(1);
});

it('records check execution errors and continues through subsequent checks in reporting mode', function () {
    $validator = new SportValidator;
    $checks = [new class implements ValidationCheck
    {
        public function run(string $sport, array $profile): ?array
        {
            throw new RuntimeException('Database password should not be copied into a finding');
        }
    }, new class implements ValidationCheck
    {
        public function run(string $sport, array $profile): ?array
        {
            return healthLifecycleResult('passing');
        }
    }];
    (new ReflectionProperty($validator, 'fullChecks'))->setValue($validator, $checks);
    app()->instance(SportValidator::class, $validator);
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--report-only' => true]))->assertFailed();
    $run = ValidationRun::latest('id')->first();
    expect($run->summary['execution_status'])->toBe('failed')->and($run->findings()->count())->toBe(2)
        ->and(json_encode($run->findings()->get()))->not->toContain('Database password');
});

it('rolls back both persisted finding views if either write fails', function () {
    $recorder = app(ValidationRunRecorder::class);
    $run = $recorder->begin('sport:nba', 'full');
    $event = 'eloquent.creating: '.ValidationFinding::class;
    Event::listen($event, fn () => throw new RuntimeException('Finding storage unavailable'));
    try {
        expect(fn () => $recorder->record($run, 'nba', healthLifecycleResult()))->toThrow(RuntimeException::class);
        expect(Healthcheck::count())->toBe(0)->and(ValidationFinding::count())->toBe(0);
    } finally {
        Event::forget($event);
    }
});

it('preserves findings if optional summary generation fails and does not cache that run as complete', function () {
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldReceive('results')->once()->andReturn([healthLifecycleResult('passing')]));
    $this->mock(ValidationReviewSummaryService::class, fn ($mock) => $mock->shouldReceive('buildFallbackSummary')->once()->andThrow(new RuntimeException('Summary failed')));
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--report-only' => true]))->assertFailed();
    $run = ValidationRun::latest('id')->first();
    expect($run->status)->toBe('passing')->and($run->summary['execution_status'])->toBe('failed')
        ->and($run->completed_at)->not->toBeNull()->and($run->findings()->count())->toBe(1)
        ->and(app(ValidationRunRecorder::class)->recent('sport:nba', 'full', 120))->toBeNull();
});

it('rejects invalid sports and scopes before creating audit records', function () {
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--sport' => 'nflx']))->assertFailed();
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--scope' => 'invalid']))->assertFailed();
    $this->artisan('healthcheck:run', ['--sport' => 'nflx'])->assertFailed();
    expect(ValidationRun::count())->toBe(0)->and(Healthcheck::count())->toBe(0);
});

it('does not run duplicate validation while a matching scope lock is held', function () {
    $lock = Cache::lock('healthcheck:validation:sport:nba:full', 7200);
    $lock->get();
    try {
        $this->artisan('healthcheck:validate-data', healthLifecycleOptions())->assertFailed();
        expect(ValidationRun::count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('fails when no configured checks execute even in reporting mode', function () {
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldReceive('results')->once()->andReturn([]));
    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--report-only' => true]))->assertFailed();
    expect(ValidationRun::latest('id')->first()->summary['execution_status'])->toBe('failed');
});

it('compares only matching completed audits when looking for regressions', function () {
    $recorder = app(ValidationRunRecorder::class);
    $full = $recorder->begin('sport:nba', 'full');
    $recorder->record($full, 'nba', healthLifecycleResult('passing'));
    $recorder->complete($full);
    $data = $recorder->begin('sport:nba', 'data');
    $recorder->record($data, 'nba', healthLifecycleResult());
    $recorder->complete($data);
    $interrupted = $recorder->begin('sport:nba', 'full');
    $recorder->complete($interrupted, new RuntimeException('Incomplete'));
    $current = $recorder->begin('sport:nba', 'full');
    $recorder->complete($current);
    expect($recorder->previousCompleted($current)?->id)->toBe($full->id);
});

it('does not send a false regression alert by comparing full checks to a data-only run', function () {
    Notification::fake();
    config(['validation.regression_alerts.enabled' => true]);
    User::factory()->admin()->create();
    $recorder = app(ValidationRunRecorder::class);
    $baseline = $recorder->begin('sport:nba', 'full');
    $recorder->record($baseline, 'nba', healthLifecycleResult());
    $recorder->complete($baseline);
    $data = $recorder->begin('sport:nba', 'data');
    $recorder->record($data, 'nba', healthLifecycleResult('passing'));
    $recorder->complete($data);
    $current = $recorder->begin('sport:nba', 'full');
    $recorder->record($current, 'nba', healthLifecycleResult());
    $recorder->complete($current);
    app(ValidationRegressionAlertService::class)->maybeNotify($current);
    Notification::assertNothingSent();
});

it('coordinates all-sports validation with individual sport locks and releases partial acquisitions', function () {
    $nba = Cache::lock('healthcheck:validation:sport:nba:full', 7200);
    $nba->get();
    try {
        $this->artisan('healthcheck:validate-data', ['--skip-ai-summary' => true, '--skip-alerts' => true])->assertFailed();
        expect(ValidationRun::count())->toBe(0);
        $mlb = Cache::lock('healthcheck:validation:sport:mlb:full', 7200);
        expect($mlb->get())->toBeTrue();
        $mlb->release();
    } finally {
        $nba->release();
    }
});

it('still alerts on a real regression when optional summary generation fails', function () {
    Notification::fake();
    config(['validation.regression_alerts.enabled' => true]);
    $admin = User::factory()->admin()->create();
    $recorder = app(ValidationRunRecorder::class);
    $baseline = $recorder->begin('sport:nba', 'full');
    $recorder->record($baseline, 'nba', healthLifecycleResult('passing'));
    $recorder->complete($baseline);
    $this->mock(SportValidator::class, fn ($mock) => $mock->shouldReceive('results')->once()->andReturn([healthLifecycleResult()]));
    $this->mock(ValidationReviewSummaryService::class, fn ($mock) => $mock->shouldReceive('buildFallbackSummary')->once()->andThrow(new RuntimeException('Optional summary failed')));

    $this->artisan('healthcheck:validate-data', healthLifecycleOptions(['--skip-alerts' => false, '--report-only' => true]))->assertFailed();

    $current = ValidationRun::latest('id')->first();
    expect($current->status)->toBe('failing')->and($current->summary['checks_execution_status'])->toBe('completed')
        ->and($current->summary['execution_status'])->toBe('failed');
    Notification::assertSentTo($admin, ValidationRegressionAlert::class);
    expect($recorder->recent('sport:nba', 'full', 120))->toBeNull();
    $next = $recorder->begin('sport:nba', 'full');
    expect($recorder->previousCompleted($next)?->id)->toBe($current->id);
});

it('does not let a skipped or failed notification consume the regression alert baseline', function (bool $skipAlerts) {
    Notification::fake();
    config(['validation.regression_alerts.enabled' => true]);
    $admin = User::factory()->admin()->create();
    $recorder = app(ValidationRunRecorder::class);
    $baseline = $recorder->begin('sport:nba', 'full');
    $recorder->record($baseline, 'nba', healthLifecycleResult('passing'));
    $recorder->complete($baseline);
    $undelivered = $recorder->begin('sport:nba', 'full', $skipAlerts);
    $recorder->record($undelivered, 'nba', healthLifecycleResult());
    $recorder->complete($undelivered);
    if (! $skipAlerts) {
        $recorder->recordPostProcessingError($undelivered, 'alerts', new RuntimeException('Alert delivery failed'));
    }
    $current = $recorder->begin('sport:nba', 'full');
    $recorder->record($current, 'nba', healthLifecycleResult());
    $recorder->complete($current);
    app(ValidationRegressionAlertService::class)->maybeNotify($current);
    Notification::assertSentTo($admin, ValidationRegressionAlert::class);
})->with([true, false]);
