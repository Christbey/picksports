<?php

namespace App\Services\Infrastructure;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Throwable;

class InfrastructureMetricsCollector
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly QueueManager $queues,
        private readonly RedisManager $redis,
    ) {}

    /**
     * Collect bounded infrastructure metrics without enumerating queue payloads
     * or application tables.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $errors = [];

        return [
            'captured_at' => now('UTC')->toIso8601String(),
            'redis' => $this->redisMetrics($errors),
            'queue' => $this->queueMetrics($errors),
            'failed_jobs' => $this->failedJobMetrics($errors),
            'scheduler' => $this->schedulerMetrics($errors),
            'database' => $this->databaseMetrics($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, array{component: string, message: string}>  $errors
     * @return array<string, int|float|string|null>
     */
    private function redisMetrics(array &$errors): array
    {
        $queueConnectionName = $this->queueConnectionName();
        $redisConnectionName = (string) (config('infrastructure.health.redis_connection')
            ?: config("queue.connections.{$queueConnectionName}.connection")
            ?: 'default');

        try {
            $info = $this->flattenRedisInfo($this->redis->connection($redisConnectionName)->command('info'));
            $usedMemory = $this->integerValue($info['used_memory'] ?? null);
            $maxMemory = $this->integerValue($info['maxmemory'] ?? null);
            $evictedKeys = $this->integerValue($info['evicted_keys'] ?? null);

            return [
                'connection' => $redisConnectionName,
                'used_memory_bytes' => $usedMemory,
                'max_memory_bytes' => $maxMemory,
                'memory_utilization_percent' => $this->percentage($usedMemory, $maxMemory),
                'evicted_keys' => $evictedKeys,
            ];
        } catch (Throwable) {
            $errors[] = [
                'component' => 'redis',
                'message' => 'Redis server metrics are unavailable.',
            ];

            return [
                'connection' => $redisConnectionName,
                'used_memory_bytes' => null,
                'max_memory_bytes' => null,
                'memory_utilization_percent' => null,
                'evicted_keys' => null,
            ];
        }
    }

    /**
     * @param  array<int, array{component: string, message: string}>  $errors
     * @return array<string, mixed>
     */
    private function queueMetrics(array &$errors): array
    {
        $connectionName = $this->queueConnectionName();
        $driver = (string) config("queue.connections.{$connectionName}.driver", 'unknown');
        $queueNames = array_values(array_unique(array_filter(array_map(
            fn (mixed $queue): string => trim((string) $queue),
            (array) config('infrastructure.health.queue_names', ['default']),
        ))));
        $queueNames = $queueNames === [] ? ['default'] : $queueNames;
        $totals = [
            'ready' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'oldest_ready_age_seconds' => null,
        ];

        try {
            $connection = $this->queues->connection($connectionName);
            $metrics = match (true) {
                $connection instanceof RedisQueue => $this->redisQueueMetrics($connection, $queueNames),
                $connection instanceof DatabaseQueue => $this->databaseQueueMetrics($connectionName, $queueNames),
                default => throw new \RuntimeException('Unsupported queue driver.'),
            };

            foreach ($metrics as $metric) {
                $totals['ready'] += $metric['ready'];
                $totals['delayed'] += $metric['delayed'];
                $totals['reserved'] += $metric['reserved'];
                $age = $metric['oldest_ready_age_seconds'];
                $totals['oldest_ready_age_seconds'] = $age === null
                    ? $totals['oldest_ready_age_seconds']
                    : max((int) ($totals['oldest_ready_age_seconds'] ?? 0), $age);
            }

            return [
                'connection' => $connectionName,
                'driver' => $driver,
                'queues' => $metrics,
                ...$totals,
            ];
        } catch (Throwable) {
            $errors[] = [
                'component' => 'queue',
                'message' => 'Queue metrics are unavailable for the configured connection.',
            ];

            return [
                'connection' => $connectionName,
                'driver' => $driver,
                'queues' => [],
                'ready' => null,
                'delayed' => null,
                'reserved' => null,
                'oldest_ready_age_seconds' => null,
            ];
        }
    }

    /**
     * @param  array<int, string>  $queueNames
     * @return array<string, array{ready: int, delayed: int, reserved: int, oldest_ready_age_seconds: int|null}>
     */
    private function redisQueueMetrics(RedisQueue $connection, array $queueNames): array
    {
        return collect($queueNames)->mapWithKeys(function (string $queue) use ($connection): array {
            $createdAt = $connection->creationTimeOfOldestPendingJob($queue);

            return [$queue => [
                'ready' => (int) $connection->pendingSize($queue),
                'delayed' => (int) $connection->delayedSize($queue),
                'reserved' => (int) $connection->reservedSize($queue),
                'oldest_ready_age_seconds' => $createdAt === null
                    ? null
                    : max(0, now()->timestamp - (int) $createdAt),
            ]];
        })->all();
    }

    /**
     * @param  array<int, string>  $queueNames
     * @return array<string, array{ready: int, delayed: int, reserved: int, oldest_ready_age_seconds: int|null}>
     */
    private function databaseQueueMetrics(string $queueConnectionName, array $queueNames): array
    {
        $databaseConnectionName = config("queue.connections.{$queueConnectionName}.connection") ?: null;
        $table = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');
        $connection = $this->database->connection($databaseConnectionName);
        $now = now()->timestamp;

        return collect($queueNames)->mapWithKeys(function (string $queue) use ($connection, $table, $now): array {
            $row = $connection->table($table)
                ->where('queue', $queue)
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready', [$now])
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed', [$now])
                ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
                ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN created_at END) AS oldest_created_at', [$now])
                ->first();
            $oldestCreatedAt = $row?->oldest_created_at;

            return [$queue => [
                'ready' => (int) ($row?->ready ?? 0),
                'delayed' => (int) ($row?->delayed ?? 0),
                'reserved' => (int) ($row?->reserved ?? 0),
                'oldest_ready_age_seconds' => $oldestCreatedAt === null
                    ? null
                    : max(0, $now - (int) $oldestCreatedAt),
            ]];
        })->all();
    }

    /**
     * @param  array<int, array{component: string, message: string}>  $errors
     * @return array{window_minutes: int, recent_count: int|null}
     */
    private function failedJobMetrics(array &$errors): array
    {
        $window = max(1, (int) config('infrastructure.health.failed_jobs_window_minutes', 60));
        $connectionName = config('queue.failed.database') ?: null;
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            $connection = $this->database->connection($connectionName);

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException('Failed job table is missing.');
            }

            return [
                'window_minutes' => $window,
                'recent_count' => $connection->table($table)
                    ->where('failed_at', '>=', now()->subMinutes($window))
                    ->count(),
            ];
        } catch (Throwable) {
            $errors[] = [
                'component' => 'failed_jobs',
                'message' => 'Recent failed-job metrics are unavailable.',
            ];

            return ['window_minutes' => $window, 'recent_count' => null];
        }
    }

    /**
     * @param  array<int, array{component: string, message: string}>  $errors
     * @return array{window_minutes: int, failure_count: int|null}
     */
    private function schedulerMetrics(array &$errors): array
    {
        $window = max(1, (int) config('infrastructure.health.scheduler_window_minutes', 60));

        try {
            $connection = $this->database->connection();

            if (! $connection->getSchemaBuilder()->hasTable('command_heartbeats')) {
                throw new \RuntimeException('Command heartbeat table is missing.');
            }

            return [
                'window_minutes' => $window,
                'failure_count' => $connection->table('command_heartbeats')
                    ->whereIn('status', ['failure', 'failed', 'error'])
                    // Do not let this health check count its own prior failure
                    // and remain latched critical after the underlying issue
                    // has recovered.
                    ->where('command', '!=', 'infrastructure:health')
                    ->where('ran_at', '>=', now()->subMinutes($window))
                    ->count(),
            ];
        } catch (Throwable) {
            $errors[] = [
                'component' => 'scheduler',
                'message' => 'Recent scheduler-failure metrics are unavailable.',
            ];

            return ['window_minutes' => $window, 'failure_count' => null];
        }
    }

    /**
     * @param  array<int, array{component: string, message: string}>  $errors
     * @return array<string, int|float|string|null>
     */
    private function databaseMetrics(array &$errors): array
    {
        $connectionName = (string) (config('infrastructure.health.database_connection') ?: config('database.default'));
        $capacityGb = config('infrastructure.health.database_storage_capacity_gb');
        $capacityBytes = is_numeric($capacityGb) && (float) $capacityGb > 0
            ? (int) round((float) $capacityGb * 1024 ** 3)
            : null;

        try {
            $connection = $this->database->connection($connectionName);
            $driver = $connection->getDriverName();
            $values = in_array($driver, ['mysql', 'mariadb'], true)
                ? $this->mysqlDatabaseMetrics($connection)
                : $this->portableDatabaseMetrics($connection);

            return [
                'connection' => $connectionName,
                'driver' => $driver,
                'storage_used_bytes' => $values['storage_used_bytes'],
                'storage_capacity_bytes' => $capacityBytes,
                'storage_utilization_percent' => $this->percentage($values['storage_used_bytes'], $capacityBytes),
                'connections_used' => $values['connections_used'],
                'connections_max' => $values['connections_max'],
                'connections_utilization_percent' => $this->percentage($values['connections_used'], $values['connections_max']),
            ];
        } catch (Throwable) {
            $errors[] = [
                'component' => 'database',
                'message' => 'Database capacity and connection metrics are unavailable.',
            ];

            return [
                'connection' => $connectionName,
                'driver' => null,
                'storage_used_bytes' => null,
                'storage_capacity_bytes' => $capacityBytes,
                'storage_utilization_percent' => null,
                'connections_used' => null,
                'connections_max' => null,
                'connections_utilization_percent' => null,
            ];
        }
    }

    /**
     * @return array{storage_used_bytes: int, connections_used: int|null, connections_max: int|null}
     */
    private function mysqlDatabaseMetrics(Connection $connection): array
    {
        $row = $connection->selectOne(<<<'SQL'
            select
                (select coalesce(sum(data_length + index_length), 0)
                    from information_schema.tables
                    where table_schema = database()) as storage_used_bytes,
                (select variable_value
                    from performance_schema.global_status
                    where variable_name = 'Threads_connected') as connections_used,
                @@max_connections as connections_max
            SQL);
        $values = array_change_key_case((array) $row, CASE_LOWER);

        return [
            'storage_used_bytes' => (int) ($values['storage_used_bytes'] ?? 0),
            'connections_used' => $this->nullableInteger($values['connections_used'] ?? null),
            'connections_max' => $this->nullableInteger($values['connections_max'] ?? null),
        ];
    }

    /**
     * @return array{storage_used_bytes: int|null, connections_used: null, connections_max: null}
     */
    private function portableDatabaseMetrics(Connection $connection): array
    {
        $database = $connection->getDatabaseName();
        $storageUsed = $connection->getDriverName() === 'sqlite'
            && $database !== ':memory:'
            && is_file($database)
                ? filesize($database)
                : null;

        return [
            'storage_used_bytes' => is_int($storageUsed) ? $storageUsed : null,
            'connections_used' => null,
            'connections_max' => null,
        ];
    }

    private function queueConnectionName(): string
    {
        return (string) (config('infrastructure.health.queue_connection') ?: config('queue.default'));
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenRedisInfo(mixed $info): array
    {
        if (! is_array($info)) {
            return [];
        }

        $flattened = [];

        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenRedisInfo($value));
            } elseif (is_string($key)) {
                $flattened[strtolower($key)] = $value;
            }
        }

        return $flattened;
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function percentage(?int $used, ?int $capacity): ?float
    {
        if ($used === null || $capacity === null || $capacity <= 0) {
            return null;
        }

        return round(($used / $capacity) * 100, 2);
    }
}
