<?php

namespace App\Services\Billing;

class BillingFetchClock
{
    public function now(): float
    {
        return microtime(true);
    }
}
