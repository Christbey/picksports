<?php

use App\Jobs\RunScheduledCommand;
use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\ConsoleOutput;

it('only dispatches heavy scheduled work and suppresses duplicate pending runs', function () {
    Queue::fake();
    $command = 'nfl:refresh-model-monitor --limit=3';
    $this->artisan('ops:dispatch-scheduled', ['command_line' => $command, '--name' => 'NFL: Refresh Admin Monitor Reports'])->assertSuccessful();
    $this->artisan('ops:dispatch-scheduled', ['command_line' => $command, '--name' => 'NFL: Refresh Admin Monitor Reports'])->assertSuccessful();
    Queue::assertPushed(RunScheduledCommand::class, 1);
    Queue::assertPushed(RunScheduledCommand::class, fn ($job) => $job->queue === 'sync' && $job->connection === 'redis' && $job->timeout === 1700);
    expect(CommandHeartbeat::count())->toBe(0);
});

it('keeps live dispatch separate from serialized bulk work', function () {
    $live = new RunScheduledCommand('espn:sync-nfl-scoreboard 20261108', 'NFL: Live Scoreboard Sync');
    $bulk = new RunScheduledCommand('espn:sync-nfl-scoreboard 20261108 --sync', 'NFL: Sync Scoreboard');
    expect($live->queue)->toBe('default')->and($live->timeout)->toBeLessThan(120)
        ->and($bulk->queue)->toBe('sync')->and($bulk->timeout)->toBeLessThan(1800)
        ->and((new RunScheduledCommand('cfb:sync-live-betting', 'CFB: Capture Live Betting Snapshots'))->queue)->toBe('default');
});

it('records completion and external success only after the command succeeds on its worker', function () {
    Http::fake();
    Artisan::shouldReceive('call')->once()->with('memory-test:success', [], Mockery::type(ConsoleOutput::class))->andReturn(0);
    $job = new RunScheduledCommand('memory-test:success', 'Memory Test', 'https://example.test/heartbeat');
    $job->handle(app(CommandHeartbeatService::class));
    $heartbeat = CommandHeartbeat::sole();
    expect($heartbeat->source)->toBe('worker')->and($heartbeat->status)->toBe('success')
        ->and($heartbeat->metadata['exit_code'])->toBe(0)->and($heartbeat->metadata['duration_ms'])->toBeGreaterThanOrEqual(0);
    Http::assertSentCount(1);
    expect(app('queue.worker')->shouldQuit)->toBeTrue();
});

it('fails visibly without success heartbeats when a worker command fails', function () {
    Http::fake();
    Artisan::shouldReceive('call')->once()->andReturn(2);
    $job = new RunScheduledCommand('memory-test:failure', 'Memory Test', 'https://example.test/heartbeat');
    try {
        $job->handle(app(CommandHeartbeatService::class));
        test()->fail('Expected command failure');
    } catch (RuntimeException $error) {
        $job->failed($error);
    }
    expect(CommandHeartbeat::sole()->status)->toBe('failure')->and(app('queue.worker')->shouldQuit)->toBeTrue();
    Http::assertNothingSent();
});

it('makes every scheduled command a lightweight foreground dispatcher', function () {
    foreach (app(Schedule::class)->events() as $event) {
        if (! $event->command) {
            continue;
        }
        expect($event->command)->toContain('ops:dispatch-scheduled')->and($event->runInBackground)->toBeFalse();
    }
});

it('preserves quoted command arguments when a scheduled command executes without a shell', function () {
    $received = null;
    Artisan::command('memory-test:options {--model=}', function () use (&$received) {
        $received = $this->option('model');
    });
    $job = new RunScheduledCommand('memory-test:options --model='.escapeshellarg(CommandHeartbeat::class), 'Memory Test');
    $job->handle(app(CommandHeartbeatService::class));
    expect($received)->toBe(CommandHeartbeat::class);
});

it('leaves completion reporting to nested pipeline workers', function () {
    Http::fake();
    Artisan::shouldReceive('call')->once()->andReturn(0);
    (new RunScheduledCommand('cfb:run-pregame-pipeline --queued', 'CFB: Generate Canonical Predictions', 'https://example.test/heartbeat'))
        ->handle(app(CommandHeartbeatService::class));
    expect(CommandHeartbeat::count())->toBe(0);
    Http::assertNothingSent();
});

it('logs one timing sample for a worker attempt without including its payload', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['createdAt' => time() - 5, 'data' => ['secret' => 'omit']]);
    $job->shouldReceive('resolveName')->andReturn('test-job');
    $job->shouldReceive('uuid')->andReturn('test-uuid');
    $job->shouldReceive('getQueue')->andReturn('sync');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('hasFailed')->andReturnFalse();
    $job->shouldReceive('isReleased')->andReturnFalse();
    Log::shouldReceive('info')->once()->with('queue.job.timing',
        Mockery::on(fn (array $timing): bool => $timing['outcome'] === 'completed'
            && $timing['enqueue_to_start_ms'] >= 4500 && $timing['runtime_ms'] >= 0
            && $timing['process_peak_bytes'] > 0 && ! array_key_exists('data', $timing)));

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));
    event(new JobProcessed('redis', $job));
});
