<?php

use App\Models\CalculationRun;
use App\Models\CalculationRunAttempt;
use Illuminate\Support\Str;

function integrityRunningCalculationAttempt(): CalculationRunAttempt
{
    return CalculationRun::factory()->create(['status' => 'running'])->attempts()->create([
        'attempt_number' => 1,
        'owner_token' => (string) Str::uuid(),
        'status' => 'running',
        'started_at' => now(),
        'lease_expires_at' => now()->addMinutes(5),
    ])->fresh();
}

it('protects attempt identity through ordinary and quiet model saves', function (bool $quiet) {
    $attempt = integrityRunningCalculationAttempt();
    $before = $attempt->getAttributes();
    $attempt->owner_token = (string) Str::uuid();
    expect(fn () => $quiet ? $attempt->saveQuietly() : $attempt->save())->toThrow(LogicException::class);
    expect($attempt->fresh()->getAttributes())->toBe($before);
})->with([false, true]);

it('protects attempt history through ordinary and quiet deletion and numeric APIs', function (string $method) {
    $attempt = integrityRunningCalculationAttempt();
    $before = $attempt->getAttributes();
    expect(fn () => in_array($method, ['delete', 'deleteQuietly'], true)
        ? $attempt->{$method}() : $attempt->{$method}('attempt_number'))->toThrow(LogicException::class);
    expect($attempt->fresh()->getAttributes())->toBe($before);
})->with(['delete', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly']);

it('refuses a stale running object after another object completed its persisted attempt', function (string $status, bool $quiet) {
    $attempt = integrityRunningCalculationAttempt();
    $stale = $attempt->fresh();
    $attempt->update(['status' => $status, 'completed_at' => now(), 'failure_code' => 'original_receipt']);
    $before = $attempt->fresh()->getAttributes();
    $stale->fill(['status' => 'failed', 'failure_code' => 'late_worker']);
    expect(fn () => $quiet ? $stale->saveQuietly() : $stale->save())->toThrow(LogicException::class);
    expect($attempt->fresh()->getAttributes())->toBe($before);
})->with(['succeeded', 'failed', 'abandoned'])->with([false, true]);

it('permits current running attempts to complete through ordinary and quiet saves', function (bool $quiet) {
    $attempt = integrityRunningCalculationAttempt();
    $attempt->fill(['status' => 'succeeded', 'completed_at' => now(), 'output_hash' => hash('sha256', 'output')]);
    expect($quiet ? $attempt->saveQuietly() : $attempt->save())->toBeTrue();
    expect($attempt->fresh()->status)->toBe('succeeded');
})->with([false, true]);

it('refuses changing the primary key of an existing attempt', function () {
    $attempt = integrityRunningCalculationAttempt();
    $id = $attempt->id;
    $attempt->id = $id + 100;
    expect(fn () => $attempt->saveQuietly())->toThrow(LogicException::class);
    expect(CalculationRunAttempt::query()->sole()->id)->toBe($id);
});
