<?php

namespace App\Services\Infrastructure;

use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

class InfrastructureFailureMetrics
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @param array<int, array{component: string, message: string}> $errors */
    public function failedJobs(array &$errors): array
    {
        $window = max(1, (int) config('infrastructure.health.failed_jobs_window_minutes', 60));
        try {
            $connection = $this->database->connection(config('queue.failed.database') ?: null);
            $table = (string) config('queue.failed.table', 'failed_jobs');
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Failed job table is missing.');
            }
            $grammar = $connection->getQueryGrammar();
            $payload = $grammar->wrap('payload');
            $name = $grammar->wrap('payload->displayName');
            $command = $grammar->wrap('payload->data->commandName');
            // Inspect only the JSON envelope. Never deserialize queued commands.
            // Unknown or malformed payloads remain actionable failures.
            $monitor = "CASE WHEN JSON_VALID({$payload}) THEN CASE WHEN {$name} = ? AND {$command} = ? THEN 1 ELSE 0 END ELSE 0 END";
            $row = $connection->table($table)
                ->whereBetween('failed_at', [now()->subMinutes($window), now()])
                ->selectRaw('COUNT(*) AS total_count')
                ->selectRaw("COALESCE(SUM({$monitor}), 0) AS monitor_count", ['Infrastructure: Health Check', 'App\\Jobs\\RunScheduledCommand'])
                ->first();

            return [
                'window_minutes' => $window,
                'recent_count' => (int) $row->total_count - (int) $row->monitor_count,
                'total_count' => (int) $row->total_count,
                'excluded_monitor_count' => (int) $row->monitor_count,
            ];
        } catch (Throwable $error) {
            report($error);
            $errors[] = ['component' => 'failed_jobs', 'message' => 'Recent failed-job metrics are unavailable.'];

            return ['window_minutes' => $window, 'recent_count' => null, 'total_count' => null, 'excluded_monitor_count' => null];
        }
    }

    /** @param array<int, array{component: string, message: string}> $errors */
    public function scheduler(array &$errors): array
    {
        $window = max(1, (int) config('infrastructure.health.scheduler_window_minutes', 60));
        try {
            $connection = $this->database->connection();
            if (! $connection->getSchemaBuilder()->hasTable('command_heartbeats')) {
                throw new RuntimeException('Command heartbeat table is missing.');
            }
            $query = $connection->table('command_heartbeats')
                ->whereIn('status', ['failure', 'failed', 'error'])
                ->whereBetween('ran_at', [now()->subMinutes($window), now()]);
            $total = (clone $query)->count();
            $excluded = (clone $query)->where(fn ($q) => $q->where('command', 'infrastructure:health')
                ->orWhere('command', 'like', 'infrastructure:health %'))->count();

            return ['window_minutes' => $window, 'failure_count' => $total - $excluded, 'total_count' => $total, 'excluded_monitor_count' => $excluded];
        } catch (Throwable $error) {
            report($error);
            $errors[] = ['component' => 'scheduler', 'message' => 'Recent scheduler-failure metrics are unavailable.'];

            return ['window_minutes' => $window, 'failure_count' => null, 'total_count' => null, 'excluded_monitor_count' => null];
        }
    }
}
