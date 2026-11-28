<?php

use App\Models\ModelTrainingAttempt;
use App\Services\ML\TrainingAttemptService;
use App\Services\Predictions\ModelRunRecorder;

beforeEach(function () {
    $this->cycle = app(ModelRunRecorder::class)->create(
        sport: 'nfl', runType: 'weekly_training_cycle', modelVersion: 'test', featureVersion: 'test', blendVersion: 'test',
    );
    $this->cycle->update(['status' => 'running']);
    $this->attempts = app(TrainingAttemptService::class);
});

it('refuses a second active claim and recovers an expired crashed owner with a distinct run identity', function () {
    $first = $this->attempts->claim($this->cycle->id, 60);
    expect(fn () => $this->attempts->claim($this->cycle->id, 60))->toThrow(RuntimeException::class, 'active attempt');
    $this->travel(60)->seconds();
    $second = $this->attempts->claim($this->cycle->id, 60);

    expect($first->refresh()->status)->toBe('abandoned')
        ->and($second->attempt_number)->toBe(2)
        ->and($second->owner_token)->not->toBe($first->owner_token)
        ->and($second->python_run_id)->not->toBe($first->python_run_id);
    expect(fn () => $this->attempts->complete($first, str_repeat('a', 64), fn () => test()->fail('Late callback ran')))
        ->toThrow(RuntimeException::class, 'Stale training attempt owner');
    expect($this->attempts->fail($first, new RuntimeException('late error')))->toBeFalse()
        ->and($this->cycle->refresh()->status)->toBe('running');
});

it('rejects results at lease expiry even before reclamation', function () {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    $this->travel(60)->seconds();
    expect(fn () => $this->attempts->complete($owner, str_repeat('a', 64), fn () => test()->fail('Expired callback ran')))
        ->toThrow(RuntimeException::class, 'no longer active');
    expect($this->attempts->fail($owner, new RuntimeException('late error')))->toBeFalse();
});

it('returns the durable receipt for duplicate completions without repeating persistence', function () {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    $hash = str_repeat('a', 64);
    $receipt = ['artifact_id' => 'one-artifact', 'status' => 'completed'];
    $first = $this->attempts->complete($owner, $hash, fn () => $receipt);
    $this->travel(120)->seconds();
    $duplicate = $this->attempts->complete($owner, $hash, fn () => test()->fail('Duplicate callback ran'));

    // Native JSON storage may reorder object members; compare strict contents.
    ksort($receipt);
    ksort($first);
    ksort($duplicate);
    expect($first)->toBe($receipt)->and($duplicate)->toBe($receipt)
        ->and($owner->refresh()->status)->toBe('succeeded')
        ->and($this->cycle->refresh()->status)->toBe('completed');
    expect(fn () => $this->attempts->complete($owner, str_repeat('b', 64), fn () => []))
        ->toThrow(RuntimeException::class, 'Conflicting training completion');
    expect(fn () => $this->attempts->claim($this->cycle->id, 60))->toThrow(RuntimeException::class);
});

it('rolls back persistence when completion crashes and allows a clean retry', function () {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    $persist = function (): array {
        $this->cycle->update(['artifact_path' => '/partially-registered']);
        throw new RuntimeException('registration interrupted');
    };
    expect(fn () => $this->attempts->complete($owner, str_repeat('a', 64), $persist))->toThrow(RuntimeException::class, 'interrupted');
    expect($this->cycle->refresh()->artifact_path)->toBeNull()
        ->and($owner->refresh()->status)->toBe('running');
    $receipt = $this->attempts->complete($owner, str_repeat('a', 64), fn () => ['artifact_id' => 'recovered']);
    expect($receipt)->toBe(['artifact_id' => 'recovered']);
});

it('rolls back a completion that outlives its lease', function () {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    expect(fn () => $this->attempts->complete($owner, str_repeat('a', 64), function (): array {
        $this->cycle->update(['artifact_path' => '/partial']);
        $this->travel(60)->seconds();

        return [];
    }))->toThrow(RuntimeException::class, 'no longer active');
    expect($this->cycle->refresh()->artifact_path)->toBeNull()
        ->and($owner->refresh()->status)->toBe('running');
});

it('retries reported failures with bounded attempts and retains terminal audit history', function () {
    config()->set('ml.training.maximum_attempts', 2);
    $first = $this->attempts->claim($this->cycle->id, 60);
    expect($this->attempts->fail($first, new RuntimeException('failed')))->toBeTrue();
    $second = $this->attempts->claim($this->cycle->id, 60);
    $this->travel(60)->seconds();
    expect(fn () => $this->attempts->claim($this->cycle->id, 60))->toThrow(RuntimeException::class, 'limit reached');
    expect($second->refresh()->status)->toBe('abandoned')
        ->and($first->refresh()->status)->toBe('failed')
        ->and($this->cycle->refresh()->status)->toBe('failed')
        ->and(ModelTrainingAttempt::query()->count())->toBe(2);
    expect(fn () => $first->update(['result' => ['changed']]))->toThrow(LogicException::class);
});

it('rejects forged ownership and leaves the active attempt untouched', function () {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    $forged = clone $owner;
    $forged->owner_token = 'wrong-token';
    expect(fn () => $this->attempts->complete($forged, str_repeat('a', 64), fn () => []))->toThrow(RuntimeException::class, 'Stale');
    expect($this->attempts->fail($forged, new RuntimeException('forged')))->toBeFalse()
        ->and($owner->refresh()->status)->toBe('running');
});

it('protects persisted terminal attempts from stale instances including quiet mutation paths', function (string $method) {
    $owner = $this->attempts->claim($this->cycle->id, 60);
    $stale = clone $owner;
    $this->attempts->complete($owner, str_repeat('a', 64), fn (): array => ['artifact_id' => 'unchanged']);
    expect(fn () => match ($method) {
        'saveQuietly' => $stale->forceFill(['result' => ['corrupted']])->saveQuietly(),
        'update' => $stale->update(['result' => ['corrupted']]),
        'updateQuietly' => $stale->updateQuietly(['result' => ['corrupted']]),
        'deleteQuietly' => $stale->deleteQuietly(),
        'incrementQuietly' => $stale->incrementQuietly('attempt_number'),
        'decrementQuietly' => $stale->decrementQuietly('attempt_number'),
    })->toThrow(LogicException::class);
    expect($owner->refresh()->status)->toBe('succeeded')
        ->and($owner->result)->toBe(['artifact_id' => 'unchanged']);
})->with(['saveQuietly', 'update', 'updateQuietly', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly']);
