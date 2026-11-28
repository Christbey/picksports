<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class BillingCustomerIdentity
{
    /** Legacy users.stripe_id may have a case-insensitive MySQL collation. */
    public function users(string $customerId, bool $current = true): Collection
    {
        $query = User::query()->where('stripe_id', $customerId);
        $driver = $query->getModel()->getConnection()->getDriverName();
        $query->whereRaw($driver === 'mysql' ? 'CAST(stripe_id AS BINARY) = ?' : 'stripe_id COLLATE BINARY = ?', [$customerId]);
        if ($current) {
            $query->lockForUpdate();
        }

        return $query->limit(2)->get()->filter(fn (User $user): bool => $user->stripe_id === $customerId)->values();
    }
}
