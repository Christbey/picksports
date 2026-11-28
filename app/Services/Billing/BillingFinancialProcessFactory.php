<?php

namespace App\Services\Billing;

use Symfony\Component\Process\Process;

final class BillingFinancialProcessFactory extends BillingSnapshotProcessFactory
{
    public function create(): Process
    {
        return new Process([PHP_BINARY, base_path('scripts/billing_provider_financial.php')], base_path());
    }
}
