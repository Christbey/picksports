<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

class SubscriptionPeriodEndResolver
{
    public function resolve(Subscription $subscription): ?CarbonInterface
    {
        // Cashier retrieves Stripe subscription items here. Call before acquiring SQL locks.
        return $subscription->currentPeriodEnd();
    }
}
