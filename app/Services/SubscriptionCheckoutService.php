<?php

namespace App\Services;

use App\Models\User;

class SubscriptionCheckoutService
{
    /**
     * @param  array<string, mixed>  $sessionOptions
     */
    public function createCheckoutUrl(User $user, string $stripePriceId, array $sessionOptions = []): string
    {
        return $user->newSubscription('default', $stripePriceId)
            ->checkout($sessionOptions)
            ->url;
    }
}
