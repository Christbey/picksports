<?php

use App\Jobs\RunScheduledCommand;
use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches the identical MLB command under both real schedule names', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $command = 'mlb:generate-predictions --season=2026 --days-forward=2';
    foreach (['MLB: Generate Predictions', 'MLB: Refresh Predictions After Odds Sync'] as $name) {
        $this->artisan('ops:dispatch-scheduled', ['command_line' => $command, '--name' => $name])->assertSuccessful();
    }
    Queue::assertPushed(RunScheduledCommand::class, 2);
    $jobs = Queue::pushed(RunScheduledCommand::class);
    expect($jobs[0]->uniqueId())->not->toBe($jobs[1]->uniqueId());
});

it('preserves the actual shared NFL scheduler mutex on its dispatch events', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => in_array($event->description, ['NFL: Pregame Pipeline', 'NFL: Canonical Cutover Readiness Sentinel'], true))->values();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->mutexName())->toBe('nfl-canonical-generation-readiness')
            ->and($event->command)->toContain('ops:dispatch-scheduled');
    }
});

it('does not enter another worker command while its scheduling group is executing', function (string $outerCommand, string $outerName, string $innerCommand, string $innerName) {
    Http::preventStrayRequests();
    $heartbeats = Mockery::mock(CommandHeartbeatService::class);
    $heartbeats->shouldReceive('inferSportFromCommand')->andReturn('test');
    $heartbeats->shouldReceive('recordSuccess')->andReturnNull();
    $outerActive = false;
    $innerEnteredWhileOuterActive = false;
    $inner = (new RunScheduledCommand($innerCommand, $innerName))->withFakeQueueInteractions();
    $depth = 0;
    Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$depth, &$innerEnteredWhileOuterActive, &$outerActive, $inner, $heartbeats) {
        if ($depth > 0) {
            $innerEnteredWhileOuterActive = $outerActive;

            return 0;
        }
        $depth++;
        $outerActive = true;
        try {
            $inner->handle($heartbeats);
        } finally {
            $outerActive = false;
            $depth--;
        }

        return 0;
    });
    (new RunScheduledCommand($outerCommand, $outerName))->handle($heartbeats);
    expect($innerEnteredWhileOuterActive)->toBeFalse();
    $inner->assertReleased(30);
})->with([
    'NFL actual shared scheduling mutex' => ['nfl:run-pregame-pipeline --season=2026 --days-forward=8', 'NFL: Pregame Pipeline', 'nfl:report-canonical-cutover-readiness --season=2026 --days-forward=8 --fail-on-not-ready', 'NFL: Canonical Cutover Readiness Sentinel'],
    'MLB actual duplicate command different names' => ['mlb:generate-predictions --season=2026 --days-forward=2', 'MLB: Generate Predictions', 'mlb:generate-predictions --season=2026 --days-forward=2', 'MLB: Refresh Predictions After Odds Sync'],
]);

class ScheduledMutexAttemptJob extends SyncJob
{
    public int $attemptNumber = 1;

    public ?int $releasedDelay = null;

    public function attempts()
    {
        return $this->attemptNumber;
    }

    public function release($delay = 0)
    {
        $this->releasedDelay = $delay;
        parent::release($delay);
    }
}

function scheduledMutexWorkerJob(RunScheduledCommand $command, int $attempt = 1): ScheduledMutexAttemptJob
{
    $queue = app('queue')->connection('sync');
    $payload = (new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $command, 'sync');
    $job = new ScheduledMutexAttemptJob(app(), $payload, 'sync', 'sync');
    $job->attemptNumber = $attempt;

    return $job;
}

it('uses actual Laravel worker admission after contention beyond attempt one then executes the unchanged payload', function () {
    Http::preventStrayRequests();
    $command = new RunScheduledCommand('nfl:run-pregame-pipeline --season=2026', 'NFL: Pregame Pipeline');
    $unique = new UniqueLock(Cache::store());
    expect($unique->acquire($command))->toBeTrue();
    $held = Cache::lock($command->executionLockKey(), 1760);
    expect($held->get())->toBeTrue();
    $first = scheduledMutexWorkerJob($command, 2);
    $worker = app('queue.worker');
    $worker->setCache(Cache::store());
    Artisan::shouldReceive('call')->once()->andReturn(0);
    $worker->process('sync', $first, new WorkerOptions(maxTries: 1));
    expect($first->hasFailed())->toBeFalse()->and($first->isReleased())->toBeTrue()->and($first->releasedDelay)->toBe(30);
    expect(CommandHeartbeat::count())->toBe(0);
    expect($unique->acquire($command))->toBeFalse();
    $held->release();
    $retry = new ScheduledMutexAttemptJob(app(), $first->getRawBody(), 'sync', 'sync');
    $retry->attemptNumber = 3;
    $worker->process('sync', $retry, new WorkerOptions(maxTries: 1));
    expect($retry->hasFailed())->toBeFalse()->and($retry->isDeleted())->toBeTrue()
        ->and(CommandHeartbeat::sole()->status)->toBe('success');
    expect($unique->acquire($command))->toBeTrue();
    $unique->release($command);
});

it('fails an ordinary command exception on its first execution even after released attempts', function () {
    Http::preventStrayRequests();
    $command = new RunScheduledCommand('mlb:generate-predictions --season=2026', 'MLB: Generate Predictions');
    $job = scheduledMutexWorkerJob($command, 4);
    $worker = app('queue.worker');
    $worker->setCache(Cache::store());
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('synthetic command failure'));
    expect(fn () => $worker->process('sync', $job, new WorkerOptions(maxTries: 1)))
        ->toThrow(RuntimeException::class, 'synthetic command failure');
    expect($job->hasFailed())->toBeTrue()->and($job->isDeleted())->toBeTrue()->and($job->isReleased())->toBeFalse()
        ->and(CommandHeartbeat::sole()->status)->toBe('failure');
    $next = Cache::lock($command->executionLockKey(), 10);
    expect($next->get())->toBeTrue();
    $next->release();
});

it('expires the fixed dispatch deadline without extending it on attempts', function () {
    $this->freezeTime();
    $command = new RunScheduledCommand('mlb:generate-predictions --season=2026', 'MLB: Generate Predictions');
    $deadline = $command->retryUntil();
    expect($deadline)->toBe(now()->getTimestamp() + 86400);
    $job = scheduledMutexWorkerJob($command, 2);
    $this->travel(86401)->seconds();
    Artisan::shouldReceive('call')->never();
    $worker = app('queue.worker');
    $worker->setCache(Cache::store());
    expect(fn () => $worker->process('sync', $job, new WorkerOptions(maxTries: 1)))
        ->toThrow(MaxAttemptsExceededException::class);
    expect($job->hasFailed())->toBeTrue()->and($command->retryUntil())->toBe($deadline);
});

it('releases its own lock on success without releasing a successor owner', function () {
    $this->freezeTime();
    $command = new RunScheduledCommand('mlb:generate-predictions --season=2026', 'MLB: Generate Predictions');
    $successor = null;
    Artisan::shouldReceive('call')->once()->andReturnUsing(function () use ($command, &$successor) {
        $this->travel($command->timeout + 61)->seconds();
        $successor = Cache::lock($command->executionLockKey(), 100);
        expect($successor->get())->toBeTrue();

        return 0;
    });
    $command->handle(app(CommandHeartbeatService::class));
    $other = Cache::lock($command->executionLockKey(), 10);
    expect($other->get())->toBeFalse();
    $successor->release();
    expect($other->get())->toBeTrue();
    $other->release();
});

it('keeps unrelated commands ungrouped and accepts old serialized jobs without new fields', function () {
    $old = new RunScheduledCommand('nfl:run-pregame-pipeline --season=2026', 'NFL: Pregame Pipeline');
    unset($old->maxExceptions, $old->executionRetryDeadline);
    $restored = unserialize(serialize($old));
    expect($restored->retryUntil())->toBeNull()->and($restored->maxExceptions)->toBeNull()
        ->and($restored->tries)->toBe(1)->and($restored->executionGroup())->toBe('nfl-canonical-generation-readiness');
    $other = new RunScheduledCommand('memory-test:other', 'Other');
    expect($other->executionGroup())->toBeNull()->and($other->retryUntil())->toBeNull()->and($other->maxExceptions)->toBeNull();
});

it('refuses an invalid lease reservation instead of running without serialization', function () {
    config(['queue.connections.redis.retry_after' => 1750]);
    Artisan::shouldReceive('call')->never();
    $job = new RunScheduledCommand('mlb:generate-predictions --season=2026', 'MLB: Generate Predictions');
    expect(fn () => $job->handle(app(CommandHeartbeatService::class)))->toThrow(RuntimeException::class, 'queue reservation');
});

it('allows unrelated command groups while an MLB execution lock is held', function () {
    $command = new RunScheduledCommand('mlb:generate-predictions --season=2026', 'MLB: Generate Predictions');
    $held = Cache::lock($command->executionLockKey(), 1760);
    expect($held->get())->toBeTrue();
    $other = new RunScheduledCommand('mlb:generate-predictions --season=2025', 'Historical different command');
    Artisan::shouldReceive('call')->once()->andReturn(0);
    try {
        $other->handle(app(CommandHeartbeatService::class));
        expect($held->isOwnedByCurrentProcess())->toBeTrue();
        $released = Cache::lock($other->executionLockKey(), 10);
        expect($released->get())->toBeTrue();
        $released->release();
    } finally {
        $held->release();
    }
});
