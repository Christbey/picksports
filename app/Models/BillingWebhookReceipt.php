<?php

namespace App\Models;

class BillingWebhookReceipt extends BillingLedgerEntry
{
    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'provider_created_at' => 'integer', 'generation' => 'integer',
            'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    protected function immutableFields(): array
    {
        return ['reconciliation_id', 'account_id', 'livemode', 'event_id', 'payload_hash', 'event_type',
            'provider_created_at', 'received_at', 'customer_id', 'subscription_id', 'generation', 'created_at'];
    }

    protected function mutableFields(): array
    {
        return ['status', 'processed_at', 'failure_category'];
    }

    protected function terminal(string $status): bool
    {
        return $status === 'applied';
    }

    protected function statuses(): array
    {
        return ['pending', 'pending_linkage', 'attention', 'applied'];
    }
}
