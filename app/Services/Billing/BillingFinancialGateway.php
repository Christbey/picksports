<?php

namespace App\Services\Billing;

/** Injectable process boundary; tests replace this service, never invoke a live child. */
class BillingFinancialGateway
{
    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly BillingFinancialProcessFactory $processes) {}

    public function execute(array $request): array
    {
        return (new BillingSnapshotProcessRunner($this->database, $this->processes))->run($request, 30);
    }
}
