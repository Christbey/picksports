<?php

namespace App\Services\Infrastructure;

class InfrastructureHealthEvaluator
{
    /**
     * @param  array<string, mixed>  $metrics
     * @return array{healthy: bool, status: string, checks: array<int, array<string, mixed>>}
     */
    public function evaluate(array $metrics): array
    {
        $oldestReadyAge = data_get($metrics, 'queue.oldest_ready_age_seconds');
        if ($oldestReadyAge === null && data_get($metrics, 'queue.ready') === 0) {
            $oldestReadyAge = 0;
        }

        $checks = [
            $this->upperBoundCheck(
                'redis_memory',
                'Redis memory utilization',
                data_get($metrics, 'redis.memory_utilization_percent'),
                'redis_memory_percent',
                '%',
            ),
            $this->upperBoundCheck(
                'redis_evictions',
                'Redis evicted keys since server start',
                data_get($metrics, 'redis.evicted_keys'),
                'redis_evicted_keys',
            ),
            $this->upperBoundCheck(
                'queue_ready',
                'Ready queue jobs',
                data_get($metrics, 'queue.ready'),
                'queue_ready',
            ),
            $this->upperBoundCheck(
                'queue_oldest_age',
                'Oldest ready queue job age',
                $oldestReadyAge,
                'queue_oldest_age_seconds',
                's',
            ),
            $this->upperBoundCheck(
                'failed_jobs',
                'Recent failed queue jobs',
                data_get($metrics, 'failed_jobs.recent_count'),
                'failed_jobs',
            ),
            $this->upperBoundCheck(
                'scheduler_failures',
                'Recent scheduled-command failures',
                data_get($metrics, 'scheduler.failure_count'),
                'scheduler_failures',
            ),
            $this->upperBoundCheck(
                'database_storage',
                'Database storage utilization',
                data_get($metrics, 'database.storage_utilization_percent'),
                'database_storage_percent',
                '%',
            ),
            $this->upperBoundCheck(
                'database_connections',
                'Database connection utilization',
                data_get($metrics, 'database.connections_utilization_percent'),
                'database_connections_percent',
                '%',
            ),
        ];

        $status = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'critical')
            ? 'critical'
            : (collect($checks)->contains(fn (array $check): bool => $check['status'] === 'warning')
                ? 'warning'
                : 'healthy');

        return [
            'healthy' => $status !== 'critical',
            'status' => $status,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, status: string, value: int|float|null, unit: string|null, warning_threshold: float|null, critical_threshold: float|null, message: string}
     */
    private function upperBoundCheck(
        string $name,
        string $label,
        mixed $value,
        string $thresholdKey,
        ?string $unit = null,
    ): array {
        $numericValue = is_numeric($value) ? (float) $value : null;
        $warning = $this->threshold($thresholdKey, 'warning');
        $critical = $this->threshold($thresholdKey, 'critical');

        if ($numericValue === null) {
            $status = (bool) config('infrastructure.health.fail_on_unavailable', true)
                ? 'critical'
                : 'warning';

            return $this->result(
                $name,
                $status,
                null,
                $unit,
                $warning,
                $critical,
                "{$label} is unavailable.",
            );
        }

        $status = match (true) {
            $critical !== null && $numericValue > $critical => 'critical',
            $warning !== null && $numericValue > $warning => 'warning',
            default => 'healthy',
        };
        $display = $this->displayValue($numericValue).($unit ?? '');
        $message = match ($status) {
            'critical' => "{$label} is {$display}, above the critical limit {$critical}{$unit}.",
            'warning' => "{$label} is {$display}, above the warning limit {$warning}{$unit}.",
            default => "{$label} is {$display}.",
        };

        return $this->result($name, $status, $numericValue, $unit, $warning, $critical, $message);
    }

    /**
     * @return array{name: string, status: string, value: int|float|null, unit: string|null, warning_threshold: float|null, critical_threshold: float|null, message: string}
     */
    private function result(
        string $name,
        string $status,
        int|float|null $value,
        ?string $unit,
        ?float $warning,
        ?float $critical,
        string $message,
    ): array {
        return [
            'name' => $name,
            'status' => $status,
            'value' => $value,
            'unit' => $unit,
            'warning_threshold' => $warning,
            'critical_threshold' => $critical,
            'message' => $message,
        ];
    }

    private function threshold(string $key, string $severity): ?float
    {
        $value = config("infrastructure.health.thresholds.{$key}.{$severity}");

        return is_numeric($value) ? (float) $value : null;
    }

    private function displayValue(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : (string) round($value, 2);
    }
}
