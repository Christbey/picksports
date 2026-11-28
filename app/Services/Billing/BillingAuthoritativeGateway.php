<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;

class BillingAuthoritativeGateway
{
    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly BillingSnapshotProcessRunner $runner) {}

    public function fetch(BillingReconciliationAttempt $owner): array
    {
        $this->database->assert();
        $context = new BillingAccountContext;
        $attempt = BillingReconciliationAttempt::query()->findOrFail($owner->id);
        $state = BillingCustomerReconciliation::query()->findOrFail($attempt->reconciliation_id);
        if (! hash_equals($attempt->owner_token, $owner->owner_token) || $attempt->status !== 'running'
            || $state->status !== 'processing' || $state->owner_token === null || ! hash_equals($state->owner_token, $attempt->owner_token)
            || $state->generation !== $attempt->generation || $state->lease_sequence !== $attempt->lease_sequence
            || ! $state->lease_expires_at?->isFuture() || $state->account_id !== $context->accountId || $state->livemode !== $context->livemode) {
            throw new BillingProviderFailure('provider_stale_owner');
        }

        return $this->runner->run([...$context->scope(), 'customer_id' => $state->customer_id,
            'targets' => $attempt->targets, 'customer_deletion_event_id' => $attempt->details['customer_deletion_event_id'] ?? null]);
    }
}
