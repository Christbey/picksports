<?php

use App\Services\Infrastructure\InfrastructureFailureMetrics;
use App\Services\Infrastructure\InfrastructureMetricsCollector;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;

/** Exercise declared defaults without consulting or changing any real environment values. */
function infrastructureEmptyEnvironmentDefaults(): array
{
    $property = new ReflectionProperty(Env::class, 'repository');
    $previous = $property->getValue();
    $property->setValue(null, RepositoryBuilder::createWithNoAdapters()->make());
    try {
        return require config_path('infrastructure.php');
    } finally {
        $property->setValue(null, $previous);
    }
}

function infrastructureSyntheticCapacityCollector(): void
{
    config(['infrastructure.health.database_connection' => 'synthetic-metrics-db',
        'infrastructure.health.queue_connection' => 'synthetic-metrics-queue',
        'infrastructure.health.redis_connection' => 'synthetic-metrics-redis',
        'queue.connections.synthetic-metrics-queue.driver' => 'redis']);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')->andReturn((object) ['storage_used_bytes' => 5 * 1024 ** 3, 'connections_used' => 2, 'connections_max' => 100]);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->with('synthetic-metrics-db')->andReturn($connection);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('creationTimeOfOldestPendingJob')->andReturn(null);
    foreach (['pendingSize', 'delayedSize', 'reservedSize'] as $method) {
        $queue->shouldReceive($method)->andReturn(0);
    }
    $queues = Mockery::mock(QueueManager::class);
    $queues->shouldReceive('connection')->with('synthetic-metrics-queue')->andReturn($queue);
    $redisConnection = Mockery::mock();
    $redisConnection->shouldReceive('command')->with('info')->andReturn(['used_memory' => 50, 'maxmemory' => 100, 'evicted_keys' => 0]);
    $redis = Mockery::mock(RedisManager::class);
    $redis->shouldReceive('connection')->with('synthetic-metrics-redis')->andReturn($redisConnection);
    $failures = Mockery::mock(InfrastructureFailureMetrics::class);
    $failures->shouldReceive('failedJobs')->andReturn(['window_minutes' => 60, 'recent_count' => 0, 'total_count' => 0, 'excluded_monitor_count' => 0]);
    $failures->shouldReceive('scheduler')->andReturn(['window_minutes' => 60, 'failure_count' => 0, 'total_count' => 0, 'excluded_monitor_count' => 0]);
    app()->instance(InfrastructureMetricsCollector::class, new InfrastructureMetricsCollector($database, $queues, $redis, $failures));
}

it('reports unknown configured capacity as unavailable rather than inventing a platform volume', function () {
    config(['infrastructure' => infrastructureEmptyEnvironmentDefaults()]);
    infrastructureSyntheticCapacityCollector();
    expect(Artisan::call('infrastructure:health', ['--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $storage = collect($report['checks'])->firstWhere('name', 'database_storage');
    expect($report['metrics']['database']['storage_capacity_bytes'])->toBeNull()
        ->and($report['metrics']['database']['storage_utilization_percent'])->toBeNull()
        ->and($storage['value'])->toBeNull()->and($storage['status'])->toBe('critical')
        ->and($report['execution_status'])->toBe('failed');
    expect(Artisan::call('infrastructure:health'))->toBe(1);
    expect(Artisan::output())->toContain('unavailable')->not->toContain('20 GiB');
});

it('uses an explicit operator capacity without changing threshold policy', function () {
    config(['infrastructure' => infrastructureEmptyEnvironmentDefaults()]);
    config(['infrastructure.health.database_storage_capacity_gb' => 10]);
    infrastructureSyntheticCapacityCollector();
    expect(Artisan::call('infrastructure:health', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['metrics']['database']['storage_capacity_bytes'])->toBe(10 * 1024 ** 3)
        ->and($report['metrics']['database']['storage_utilization_percent'])->toEqual(50)
        ->and(collect($report['checks'])->firstWhere('name', 'database_storage')['status'])->toBe('healthy');
});

it('distinguishes explicitly disabled capacity severity from measured zero utilization', function () {
    config(['infrastructure' => infrastructureEmptyEnvironmentDefaults()]);
    config(['infrastructure.health.database_storage_capacity_gb' => null,
        'infrastructure.health.thresholds.database_storage_percent' => ['warning' => null, 'critical' => null]]);
    infrastructureSyntheticCapacityCollector();
    expect(Artisan::call('infrastructure:health', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $storage = collect($report['checks'])->firstWhere('name', 'database_storage');
    expect($storage['status'])->toBe('disabled')->and($storage['value'])->toBeNull()
        ->and($report['metrics']['database']['storage_utilization_percent'])->toBeNull();
});
