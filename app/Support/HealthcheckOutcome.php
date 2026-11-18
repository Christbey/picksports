<?php

namespace App\Support;

/** Findings describe system health; execution failure means the check could not finish. */
final readonly class HealthcheckOutcome
{
    public function __construct(
        public string $status,
        public bool $executionFailed = false,
    ) {}

    public function exitCode(bool $reportOnly = false): int
    {
        return $this->executionFailed || (! $reportOnly && in_array($this->status, ['failing', 'critical'], true)) ? 1 : 0;
    }

    public function executionStatus(): string
    {
        return $this->executionFailed ? 'failed' : 'completed';
    }
}
