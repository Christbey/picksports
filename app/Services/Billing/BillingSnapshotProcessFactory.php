<?php

namespace App\Services\Billing;

use Symfony\Component\Process\Process;

class BillingSnapshotProcessFactory
{
    public function create(): Process
    {
        return new Process([PHP_BINARY, base_path('scripts/billing_provider_snapshot.php')], base_path());
    }
}
