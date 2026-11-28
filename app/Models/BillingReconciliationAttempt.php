<?php

namespace App\Models;

class BillingReconciliationAttempt extends BillingLedgerEntry
{
    protected function casts(): array
    {
        return ['generation' => 'integer', 'lease_sequence' => 'integer', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'fetch_started_at' => 'immutable_datetime',
            'fetch_completed_at' => 'immutable_datetime', 'targets' => 'array', 'before_projection' => 'array',
            'after_projection' => 'array', 'observation_evidence' => 'array', 'details' => 'array'];
    }

    protected function immutableFields(): array
    {
        return ['reconciliation_id', 'receipt_id', 'generation', 'lease_sequence', 'owner_token', 'kind', 'started_at', 'created_at', 'targets', 'details'];
    }

    protected function mutableFields(): array
    {
        return ['status', 'completed_at', 'fetch_started_at', 'fetch_completed_at', 'source_fingerprint',
            'failure_category', 'before_projection', 'after_projection', 'observation_evidence'];
    }

    protected function terminal(string $status): bool
    {
        return $status !== 'running';
    }

    protected function statuses(): array
    {
        return ['running', 'succeeded', 'retry', 'stale', 'attention', 'conflict', 'requested'];
    }
}
