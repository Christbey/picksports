<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AdminQueueSnapshot
{
    public function __construct(private readonly DatabaseManager $database, private readonly int $failureSampleLimit = 500) {}

    /** The reporting interval includes its start and excludes its end. */
    public function snapshot(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?CarbonInterface $observedAt = null): array
    {
        $observedAt ??= now();
        if ($periodStart->greaterThanOrEqualTo($periodEnd)) {
            throw new InvalidArgumentException('Queue reporting period must have a positive duration.');
        }
        $errors = [];
        $queue = $this->queue($observedAt, $errors);
        $failures = $this->failures($periodStart, $periodEnd, $observedAt, $errors);

        return [
            'observed_at' => $observedAt->toIso8601String(),
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'queue' => $queue,
            'failures' => $failures,
            'errors' => $errors,
        ];
    }

    private function queue(CarbonInterface $observedAt, array &$errors): array
    {
        $name = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$name}.driver", $name);
        $unavailable = ['available' => false, 'driver' => $driver, 'queues' => [], 'totals' => null];
        if ($driver !== 'database') {
            $errors[] = ['component' => 'queue', 'message' => 'Current queue backlog is unavailable for the configured non-database driver.'];

            return $unavailable;
        }
        try {
            $connection = $this->database->connection(config("queue.connections.{$name}.connection") ?: null);
            $table = (string) config("queue.connections.{$name}.table", 'jobs');
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Queue table is unavailable.');
            }
            $timestamp = $observedAt->getTimestamp();
            $rows = $connection->table($table)->where('created_at', '<=', $timestamp)->select('queue')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS pending_count')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS waiting_count', [$timestamp])
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed_count', [$timestamp])
                ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved_count')
                ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at ELSE NULL END) AS oldest_waiting_timestamp', [$timestamp])
                ->groupBy('queue')->orderBy('queue')->get();
            $totals = ['pending_count' => 0, 'waiting_count' => 0, 'delayed_count' => 0, 'reserved_count' => 0,
                'oldest_waiting_at' => null, 'oldest_waiting_age_seconds' => null];
            $queues = [];
            foreach ($rows as $row) {
                $oldest = $row->oldest_waiting_timestamp === null ? null : (int) $row->oldest_waiting_timestamp;
                $age = $oldest === null ? null : max(0, $timestamp - $oldest);
                $item = ['queue' => (string) $row->queue];
                foreach (['pending_count', 'waiting_count', 'delayed_count', 'reserved_count'] as $field) {
                    $item[$field] = (int) $row->{$field};
                    $totals[$field] += $item[$field];
                }
                $item['oldest_waiting_at'] = $oldest === null ? null : CarbonImmutable::createFromTimestampUTC($oldest)->toIso8601String();
                $item['oldest_waiting_age_seconds'] = $age;
                if ($age !== null && ($totals['oldest_waiting_age_seconds'] === null || $age > $totals['oldest_waiting_age_seconds'])) {
                    $totals['oldest_waiting_age_seconds'] = $age;
                    $totals['oldest_waiting_at'] = $item['oldest_waiting_at'];
                }
                $queues[] = $item;
            }

            return ['available' => true, 'driver' => $driver, 'queues' => $queues, 'totals' => $totals];
        } catch (Throwable) {
            $errors[] = ['component' => 'queue', 'message' => 'Current database queue metrics are unavailable.'];

            return $unavailable;
        }
    }

    private function failures(CarbonInterface $start, CarbonInterface $end, CarbonInterface $observedAt, array &$errors): array
    {
        $limit = max(1, min(1000, $this->failureSampleLimit));
        $unavailable = ['available' => false, 'retained_total' => null, 'period_count' => null, 'latest_failure_at' => null,
            'top_categories' => [], 'sampled_count' => 0, 'sample_limit' => $limit, 'sample_truncated' => false,
            'category_scope' => 'latest retained failures as of observation'];
        try {
            $connection = $this->database->connection(config('queue.failed.database') ?: null);
            $table = (string) config('queue.failed.table', 'failed_jobs');
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Failed job table is unavailable.');
            }
            $query = $connection->table($table)->where('failed_at', '<=', $observedAt);
            $retainedTotal = (clone $query)->count();
            $periodCount = (clone $query)->where('failed_at', '>=', $start)->where('failed_at', '<', $end)->count();
            $latest = (clone $query)->max('failed_at');
            $grammar = $connection->getQueryGrammar();
            $payload = $grammar->wrap('payload');
            $sampleQuery = (clone $query)->select('failed_at')->orderByDesc('failed_at')->orderByDesc('id')->limit($limit);
            foreach (['displayName' => 'display_name', 'data->commandName' => 'command_name', 'job' => 'job_name'] as $path => $alias) {
                $field = $grammar->wrap('payload->'.$path);
                $sampleQuery->selectRaw("CASE WHEN JSON_VALID({$payload}) THEN SUBSTR({$field}, 1, 191) ELSE NULL END AS {$alias}");
            }
            $sample = $sampleQuery->get();
            $groups = [];
            foreach ($sample as $row) {
                $category = $this->category([$row->display_name, $row->command_name, $row->job_name]);
                $groups[$category] ??= ['category' => $category, 'count' => 0, 'latest_failure_at' => (string) $row->failed_at];
                $groups[$category]['count']++;
            }
            $groups = array_values($groups);
            usort($groups, fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['category'], $b['category']));

            return ['available' => true, 'retained_total' => $retainedTotal, 'period_count' => $periodCount,
                'latest_failure_at' => $latest, 'top_categories' => array_slice($groups, 0, 5), 'sampled_count' => $sample->count(),
                'sample_limit' => $limit, 'sample_truncated' => $retainedTotal > $sample->count(),
                'category_scope' => 'latest retained failures as of observation'];
        } catch (Throwable) {
            $errors[] = ['component' => 'failed_jobs', 'message' => 'Retained failed-job metrics are unavailable.'];

            return $unavailable;
        }
    }

    /** Only code identifiers leave the envelope; free-form labels and command bodies do not. */
    private function category(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+(?:@[A-Za-z_][A-Za-z0-9_]*)?$/D', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'Unknown job';
    }
}
