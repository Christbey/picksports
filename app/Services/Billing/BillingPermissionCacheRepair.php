<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class BillingPermissionCacheRepair
{
    public function __construct(private readonly PermissionRegistrar $permissions) {}

    public function repair(BillingCustomerReconciliation $state): bool
    {
        try {
            $removed = $this->permissions->forgetCachedPermissions();
            // Some adapters return false for an already absent key; others can fail while retaining it.
            if ($removed !== true && $this->permissions->getCacheRepository()->has($this->permissions->cacheKey)) {
                return false;
            }

            return BillingCustomerReconciliation::query()->whereKey($state->id)
                ->where('cache_lease_sequence', $state->cache_lease_sequence)->where('cache_generation', $state->cache_generation)
                ->where('cache_invalidation_pending', true)->update(['cache_invalidation_pending' => false]) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
