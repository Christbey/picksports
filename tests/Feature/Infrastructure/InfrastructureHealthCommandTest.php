<?php

use App\Services\Infrastructure\InfrastructureMetricsCollector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function infrastructureMetrics(array $overrides = []): array
{
    return array_replace_recursive([
        'captured_at' => now('UTC')->toIso8601String(),
        'redis' => [
            'connection' => 'default',
            'used_memory_bytes' => 50,
            'max_memory_bytes' => 100,
            'memory_utilization_percent' => 50.0,
            'evicted_keys' => 0,
        ],
        'queue' => [
            'connection' => 'redis',
            'driver' => 'redis',
            'queues' => [
                'default' => [
                    'ready' => 0,
                    'delayed' => 0,
                    'reserved' => 0,
                    'oldest_ready_age_seconds' => null,
                ],
            ],
            'ready' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'oldest_ready_age_seconds' => null,
        ],
        'failed_jobs' => ['window_minutes' => 60, 'recent_count' => 0],
        'scheduler' => ['window_minutes' => 60, 'failure_count' => 0],
        'database' => [
            'connection' => 'mysql',
            'driver' => 'mysql',
            'storage_used_bytes' => 5,
            'storage_capacity_bytes' => 100,
            'storage_utilization_percent' => 5.0,
            'connections_used' => 2,
            'connections_max' => 100,
            'connections_utilization_percent' => 2.0,
        ],
        'errors' => [],
    ], $overrides);
}

it('returns success with a complete machine-readable infrastructure report', function () {
    $collector = Mockery::mock(InfrastructureMetricsCollector::class);
    $collector->shouldReceive('collect')->once()->andReturn(infrastructureMetrics());
    $this->app->instance(InfrastructureMetricsCollector::class, $collector);

    $exitCode = Artisan::call('infrastructure:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['healthy'])->toBeTrue()
        ->and($report['status'])->toBe('healthy')
        ->and($report['metrics']['queue']['ready'])->toBe(0)
        ->and(collect($report['checks'])->firstWhere('name', 'queue_oldest_age')['value'])->toEqual(0);
});

it('fails when a configurable critical threshold is exceeded', function () {
    config()->set('infrastructure.health.thresholds.queue_ready', [
        'warning' => 2,
        'critical' => 5,
    ]);

    $collector = Mockery::mock(InfrastructureMetricsCollector::class);
    $collector->shouldReceive('collect')->once()->andReturn(infrastructureMetrics([
        'queue' => [
            'ready' => 6,
            'oldest_ready_age_seconds' => 10,
        ],
    ]));
    $this->app->instance(InfrastructureMetricsCollector::class, $collector);

    $exitCode = Artisan::call('infrastructure:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);
    $queueCheck = collect($report['checks'])->firstWhere('name', 'queue_ready');

    expect($exitCode)->toBe(1)
        ->and($report['healthy'])->toBeFalse()
        ->and($report['status'])->toBe('critical')
        ->and($queueCheck['status'])->toBe('critical')
        ->and($queueCheck['critical_threshold'])->toEqual(5);
});

it('fails closed when a required infrastructure metric is unavailable', function () {
    config()->set('infrastructure.health.fail_on_unavailable', true);

    $collector = Mockery::mock(InfrastructureMetricsCollector::class);
    $collector->shouldReceive('collect')->once()->andReturn(infrastructureMetrics([
        'redis' => [
            'used_memory_bytes' => null,
            'max_memory_bytes' => null,
            'memory_utilization_percent' => null,
        ],
        'errors' => [[
            'component' => 'redis',
            'message' => 'Redis server metrics are unavailable.',
        ]],
    ]));
    $this->app->instance(InfrastructureMetricsCollector::class, $collector);

    $exitCode = Artisan::call('infrastructure:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and(collect($report['checks'])->firstWhere('name', 'redis_memory')['status'])->toBe('critical')
        ->and($report['metrics']['errors'])->toHaveCount(1);
});

it('collects bounded ready delayed reserved and oldest metrics from a database queue', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'sqlite');
    config()->set('infrastructure.health.queue_connection', 'database');
    config()->set('infrastructure.health.queue_names', ['default', 'sync']);
    config()->set('infrastructure.health.database_connection', 'sqlite');

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinute()->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addMinute()->timestamp,
            'created_at' => now()->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->subMinute()->timestamp,
            'created_at' => now()->subMinutes(2)->timestamp,
        ],
        [
            'queue' => 'sync',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinute()->timestamp,
            'created_at' => now()->subMinute()->timestamp,
        ],
    ]);

    $metrics = app(InfrastructureMetricsCollector::class)->collect();

    expect($metrics['queue'])
        ->ready->toBe(2)
        ->delayed->toBe(1)
        ->reserved->toBe(1)
        ->and($metrics['queue']['queues']['default']['oldest_ready_age_seconds'])->toBeGreaterThanOrEqual(600);
});

it('does not let its own previous heartbeat failure latch scheduler health critical', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'sqlite');
    config()->set('infrastructure.health.queue_connection', 'database');
    config()->set('infrastructure.health.database_connection', 'sqlite');

    DB::table('command_heartbeats')->insert([
        [
            'command' => 'infrastructure:health',
            'status' => 'failure',
            'source' => 'schedule',
            'ran_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ],
        [
            'command' => 'nfl:grade-predictions',
            'status' => 'success',
            'source' => 'schedule',
            'ran_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ],
    ]);

    $metrics = app(InfrastructureMetricsCollector::class)->collect();

    expect($metrics['scheduler']['failure_count'])->toBe(0);
});
