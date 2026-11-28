<?php

namespace App\Jobs\Billing;

use App\Services\Billing\BillingFinancialExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExecuteBillingMutation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $stateId, public readonly int $intentId, public readonly string $dispatchToken) {}

    public function handle(BillingFinancialExecutor $executor): void
    {
        $executor->run($this->stateId, $this->intentId, $this->dispatchToken);
    }
}
