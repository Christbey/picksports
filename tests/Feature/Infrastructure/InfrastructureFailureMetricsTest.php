<?php

use App\Models\CommandHeartbeat;
use App\Services\Infrastructure\InfrastructureFailureMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-11-17 18:00:00');
    config(['queue.failed.database' => 'sqlite']);
});
afterEach(fn () => $this->travelBack());

function healthFailedJob(string $payload, int $minutesAgo = 1): void
{
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'sync',
        'payload' => $payload, 'exception' => 'Failure', 'failed_at' => now()->subMinutes($minutesAgo)]);
}

it('excludes only known monitor envelopes while preserving unknown and real failures', function () {
    healthFailedJob(json_encode(['displayName' => 'Infrastructure: Health Check', 'data' => ['commandName' => 'App\\Jobs\\RunScheduledCommand']]));
    foreach ([
        ['displayName' => 'NFL: Build Research Revisions', 'data' => ['commandName' => 'App\\Jobs\\RunScheduledCommand']],
        ['displayName' => 'Infrastructure: Health Check', 'data' => ['commandName' => 'App\\Jobs\\OtherJob']],
        ['displayName' => 'Infrastructure: Health Check'],
        [],
    ] as $payload) {
        healthFailedJob(json_encode($payload));
    }
    healthFailedJob('not valid JSON');
    healthFailedJob('{}', 61);
    healthFailedJob('{}', -1);
    $errors = [];
    $metrics = app(InfrastructureFailureMetrics::class)->failedJobs($errors);

    expect($metrics)->toMatchArray(['recent_count' => 5, 'total_count' => 6, 'excluded_monitor_count' => 1])
        ->and($errors)->toBeEmpty()
        ->and(DB::table('failed_jobs')->count())->toBe(8);
});

it('recovers when the rolling window contains only its own failed jobs', function () {
    healthFailedJob(json_encode(['displayName' => 'Infrastructure: Health Check', 'data' => ['commandName' => 'App\\Jobs\\RunScheduledCommand']]));
    $errors = [];
    expect(app(InfrastructureFailureMetrics::class)->failedJobs($errors)['recent_count'])->toBe(0);
});

it('excludes its heartbeat with options without excluding similarly named or future commands', function () {
    foreach (['infrastructure:health', 'infrastructure:health --report-only', 'infrastructure:healthcheck', 'nfl:grade-predictions'] as $command) {
        CommandHeartbeat::create(['command' => $command, 'status' => 'failure', 'source' => 'worker', 'ran_at' => now()->subMinute()]);
    }
    CommandHeartbeat::create(['command' => 'future:command', 'status' => 'failure', 'source' => 'worker', 'ran_at' => now()->addMinute()]);
    $errors = [];
    expect(app(InfrastructureFailureMetrics::class)->scheduler($errors))
        ->toMatchArray(['failure_count' => 2, 'total_count' => 4, 'excluded_monitor_count' => 2]);
});

it('reports unavailable failure metrics rather than zero when the configured table is missing', function () {
    config(['queue.failed.table' => 'missing_failed_jobs']);
    $errors = [];
    expect(app(InfrastructureFailureMetrics::class)->failedJobs($errors)['recent_count'])->toBeNull()
        ->and($errors[0]['component'])->toBe('failed_jobs');
});
