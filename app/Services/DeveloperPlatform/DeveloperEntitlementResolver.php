<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperEntitlementPolicy;
use App\Models\DeveloperOrganization;

class DeveloperEntitlementResolver
{
    public function resolve(
        DeveloperOrganization $organization,
        string $productCode,
        ?string $requiredScope = null,
    ): ?DeveloperEntitlementPolicy {
        if (! $organization->isActive()) {
            return null;
        }

        $policy = $organization->entitlementPolicies()
            ->effective()
            ->whereHas('product', fn ($query) => $query
                ->where('code', $productCode)
                ->where('is_active', true))
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if ($policy === null || ($requiredScope !== null && ! $policy->allowsScope($requiredScope))) {
            return null;
        }

        return $policy;
    }
}
