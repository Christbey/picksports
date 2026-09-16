<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\InfrastructureHealthEvaluator;
use App\Services\Infrastructure\InfrastructureMetricsCollector;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class InfrastructureHealthCommand extends Command
{
    protected $signature = 'infrastructure:health
        {--json : Output machine-readable JSON}';

    protected $description = 'Report bounded Redis, queue, scheduler, and database infrastructure health metrics';

    public function handle(
        InfrastructureMetricsCollector $collector,
        InfrastructureHealthEvaluator $evaluator,
    ): int {
        try {
            $metrics = $collector->collect();
            $result = $evaluator->evaluate($metrics);
            $report = [
                ...$result,
                'metrics' => $metrics,
            ];

            if ((bool) $this->option('json')) {
                $this->output->writeln(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));
            } else {
                $this->renderReport($report);
            }

            return $result['healthy'] ? self::SUCCESS : self::FAILURE;
        } catch (JsonException) {
            $this->error('Unable to encode the infrastructure health report.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Unable to collect the infrastructure health report.');

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info('Infrastructure health: '.strtoupper((string) $report['status']));
        $this->table(
            ['Check', 'Status', 'Value', 'Warning', 'Critical'],
            collect($report['checks'])->map(fn (array $check): array => [
                $check['name'],
                strtoupper((string) $check['status']),
                $this->formatMetric($check['value'], $check['unit']),
                $this->formatMetric($check['warning_threshold'], $check['unit']),
                $this->formatMetric($check['critical_threshold'], $check['unit']),
            ])->all(),
        );

        $queueRows = collect(data_get($report, 'metrics.queue.queues', []))
            ->map(fn (array $queue, string $name): array => [
                $name,
                $queue['ready'],
                $queue['delayed'],
                $queue['reserved'],
                $queue['oldest_ready_age_seconds'] === null ? 'none' : $queue['oldest_ready_age_seconds'].'s',
            ])
            ->values()
            ->all();

        if ($queueRows !== []) {
            $this->table(['Queue', 'Ready', 'Delayed', 'Reserved', 'Oldest ready'], $queueRows);
        }

        $this->line(sprintf(
            'Redis memory: %s / %s; evicted keys: %s.',
            $this->formatBytes(data_get($report, 'metrics.redis.used_memory_bytes')),
            $this->formatBytes(data_get($report, 'metrics.redis.max_memory_bytes')),
            data_get($report, 'metrics.redis.evicted_keys', 'unavailable') ?? 'unavailable',
        ));
        $this->line(sprintf(
            'Database storage: %s / %s; connections: %s / %s.',
            $this->formatBytes(data_get($report, 'metrics.database.storage_used_bytes')),
            $this->formatBytes(data_get($report, 'metrics.database.storage_capacity_bytes')),
            data_get($report, 'metrics.database.connections_used', 'unavailable') ?? 'unavailable',
            data_get($report, 'metrics.database.connections_max', 'unavailable') ?? 'unavailable',
        ));

        foreach ((array) data_get($report, 'metrics.errors', []) as $error) {
            $this->warn(sprintf('%s: %s', $error['component'], $error['message']));
        }
    }

    private function formatMetric(mixed $value, ?string $unit): string
    {
        if (! is_numeric($value)) {
            return 'disabled/unavailable';
        }

        $numeric = (float) $value;
        $display = fmod($numeric, 1.0) === 0.0
            ? (string) (int) $numeric
            : (string) round($numeric, 2);

        return $display.($unit ?? '');
    }

    private function formatBytes(mixed $bytes): string
    {
        if (! is_numeric($bytes)) {
            return 'unavailable';
        }

        $value = (float) $bytes;
        foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
            if ($value < 1024 || $unit === 'TiB') {
                return round($value, $unit === 'B' ? 0 : 2).' '.$unit;
            }

            $value /= 1024;
        }

        return 'unavailable';
    }
}
