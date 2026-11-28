<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Ephemeral local SQL capability, installed only inside a current coordinator-owned projection transaction. */
final class BillingCanonicalWriteContext
{
    private ?array $owner = null;

    public function run(BillingCustomerReconciliation $state, BillingReconciliationAttempt $attempt, Closure $callback, bool $customerDeleted = false): mixed
    {
        if ($this->owner !== null || DB::transactionLevel() === 0) {
            throw new LogicException('Canonical billing writes require current local ownership.');
        }
        $current = BillingCustomerReconciliation::query()->lockForUpdate()->findOrFail($state->id);
        $receipt = BillingReconciliationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        if ($current->status !== 'processing' || $receipt->status !== 'running'
            || (int) $receipt->reconciliation_id !== (int) $current->id || $current->generation !== $receipt->generation
            || $current->lease_sequence !== $receipt->lease_sequence || $current->owner_token !== $receipt->owner_token
            || $receipt->owner_token !== $attempt->owner_token || ! $current->lease_expires_at?->isFuture()) {
            throw new LogicException('Canonical billing ownership changed.');
        }
        if ($customerDeleted && ! is_string($receipt->details['customer_deletion_event_id'] ?? null)) {
            throw new LogicException('Customer-wide canonical removal requires a captured deletion trigger.');
        }
        $this->owner = ['user_id' => (int) $current->user_id, 'attempt_id' => (int) $receipt->id,
            'targets' => $receipt->targets, 'customer_deleted' => $customerDeleted];
        try {
            return $callback();
        } finally {
            $this->owner = null;
        }
    }

    public function allows(int $userId, ?int $attemptId = null, ?string $subscriptionId = null): bool
    {
        return $this->owner !== null && DB::transactionLevel() > 0 && $this->owner['user_id'] === $userId
            && ($attemptId === null || $this->owner['attempt_id'] === $attemptId)
            && ($subscriptionId === null || $this->owner['customer_deleted'] || in_array($subscriptionId, $this->owner['targets'], true));
    }

    public function allowsCustomerClosure(int $userId, int $attemptId): bool
    {
        return $this->allows($userId, $attemptId) && $this->owner['customer_deleted'] === true;
    }
}
