<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class BillingDatabaseBoundary
{
    public function assert(bool $standalone = true): void
    {
        if (Cashier::$customerModel !== User::class) {
            throw new LogicException('Unsupported billing customer model.');
        }
        $connection = DB::connection();
        if ($standalone && $connection->transactionLevel() !== 0) {
            throw new LogicException('Durable billing requires a standalone database lifecycle.');
        }
        $user = new User;
        $subscription = $user->subscriptions()->getRelated();
        foreach ([new BillingCustomerReconciliation, new BillingWebhookReceipt, new BillingReconciliationAttempt,
            $user, $subscription, $subscription->items()->getRelated(), new SubscriptionTier,
            new Role, new Permission, $user->roles()->getRelated(), $user->permissions()->getRelated()] as $model) {
            if ($model->getConnection()->getName() !== $connection->getName()) {
                throw new LogicException('Durable billing models must use the default database connection.');
            }
        }
    }
}
