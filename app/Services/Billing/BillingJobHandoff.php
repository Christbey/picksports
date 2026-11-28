<?php

namespace App\Services\Billing;

use App\Jobs\Billing\ReconcileBillingCustomer;
use App\Models\BillingWebhookReceipt;
use Illuminate\Support\Facades\Bus;
use Throwable;

/** Optional latency optimization; durable lease expiry recovers a lost queue handoff. */
final class BillingJobHandoff
{
    public function __construct(private readonly BillingReconciliationCoordinator $leases) {}

    public function receipt(BillingWebhookReceipt $receipt): bool
    {
        return $receipt->wasRecentlyCreated && $this->dispatch((int) $receipt->reconciliation_id);
    }

    public function dispatch(int $stateId): bool
    {
        if (! config('billing.durable.enabled', false)) {
            return false;
        }
        $connection = (string) config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        $queue = config('queue.connections.'.$connection.'.queue', 'default');
        $budget = (int) config('billing.fetch_budget_seconds', 60);
        $timeout = $budget + 30;
        // Refuse before claiming: HTTP must never execute provider work through a synchronous queue.
        if ($budget < 1 || $budget > 120 || ! in_array($driver, ['database', 'redis'], true) || ! is_string($queue)
            || (int) config('queue.worker_contracts.'.$queue.'.timeout', 0) <= $timeout
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= $timeout) {
            return false;
        }
        try {
            $attempt = $this->leases->claim($stateId);
            if ($attempt === null) {
                return false;
            }
            Bus::dispatch((new ReconcileBillingCustomer($attempt->id, $attempt->owner_token))->onConnection($connection)->onQueue($queue));

            return true;
        } catch (Throwable) {
            // Ambiguous enqueue outcomes are safe: any eventual worker must still hold this SQL lease.
            // A new attempt cannot be admitted until this lease expires; no blind deletion/requeue.
            return false;
        }
    }
}
