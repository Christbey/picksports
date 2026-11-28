<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use Throwable;

final class BillingReconciler
{
    public function __construct(private readonly BillingReconciliationCoordinator $leases,
        private readonly BillingAuthoritativeGateway $gateway, private readonly BillingProjectionWriter $projection,
        private readonly BillingPermissionCacheRepair $cache) {}

    public function run(int $stateId): bool
    {
        if (! config('billing.durable.enabled', false)) {
            return false;
        }
        $attempt = $this->leases->claim($stateId);
        if ($attempt === null) {
            return false;
        }

        return $this->process($attempt);
    }

    public function runAttempt(int $attemptId, string $ownerToken): bool
    {
        if (! config('billing.durable.enabled', false)) {
            return false;
        }
        app(BillingDatabaseBoundary::class)->assert();
        $attempt = BillingReconciliationAttempt::query()->find($attemptId);
        if ($attempt === null || $attempt->status !== 'running' || ! hash_equals($attempt->owner_token, $ownerToken)) {
            return false;
        }

        return $this->process($attempt);
    }

    private function process(BillingReconciliationAttempt $attempt): bool
    {
        if (! $this->leases->begin($attempt)) {
            return false;
        }
        $observation = null;
        try {
            // The claim transaction is committed before any provider read.
            $observation = $this->gateway->fetch($attempt);
            $committed = $this->leases->commit($attempt, $observation, $this->projection->apply(...));
        } catch (Throwable $error) {
            $this->leases->fail($attempt, $error instanceof BillingProviderFailure ? $error->category : 'unexpected_failure', $observation);

            return false;
        }
        if ($committed) {
            // Cache failure cannot undo or rewrite a committed SQL receipt. A scanner repairs invalidation separately.
            $this->cache->repair(BillingCustomerReconciliation::query()->findOrFail($attempt->reconciliation_id));
        }

        return $committed;
    }
}
