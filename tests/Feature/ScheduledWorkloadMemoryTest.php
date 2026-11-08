<?php

use App\Jobs\RunScheduledCommand;
use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
        if (! $event->command || str_contains($event->command, '--queued')) {
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
