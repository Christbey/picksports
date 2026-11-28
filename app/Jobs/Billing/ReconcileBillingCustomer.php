<?php

namespace App\Jobs\Billing;

use App\Services\Billing\BillingReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** SQL leases, not queue delivery, own provider observation and projection admission. */
class ReconcileBillingCustomer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $attemptId, public string $ownerToken)
    {
        $this->timeout = min(120, max(1, (int) config('billing.fetch_budget_seconds', 60))) + 30;
    }

    public function handle(BillingReconciler $reconciler): void
    {
        $reconciler->runAttempt($this->attemptId, $this->ownerToken);
    }
}
