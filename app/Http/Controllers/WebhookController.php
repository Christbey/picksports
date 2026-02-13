<?php

namespace App\Http\Controllers;

use App\Models\User;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;

class WebhookController extends CashierController
{
    public function handleCustomerSubscriptionUpdated(array $payload): void
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        $this->syncUserRole($payload);
    }

    public function handleCustomerSubscriptionCreated(array $payload): void
    {
        parent::handleCustomerSubscriptionCreated($payload);

        $this->syncUserRole($payload);
    }

    public function handleCustomerSubscriptionDeleted(array $payload): void
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        $this->syncUserRole($payload);
    }

    protected function syncUserRole(array $payload): void
    {
        $customerId = $payload['data']['object']['customer'] ?? null;

        if (! $customerId) {
            return;
        }

        $user = User::where('stripe_id', $customerId)->first();

        if ($user) {
            $user->syncRoleFromTier();
        }
    }
}
