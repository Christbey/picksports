<?php

namespace App\Services\Admin;

use App\Models\SubscriptionTier;
use App\Support\PredictionDataPermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TierPermissionSyncService
{
    public function syncTierRolePermissions(SubscriptionTier $tier): Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate(['name' => $tier->slug]);

        $permissionNames = $this->resolvePermissionNamesForTier($tier);
        $role->syncPermissions($permissionNames);

        return $role;
    }

    /**
     * @return array<int, string>
     */
    public function resolvePermissionNamesForTier(SubscriptionTier $tier): array
    {
        $availablePermissions = Permission::query()->pluck('name')->all();

        $storedPermissions = collect($tier->permissions ?? [])
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->values();

        $sourcePermissions = $storedPermissions->intersect($availablePermissions)->values();

        $mappedDataPermissions = collect(PredictionDataPermissions::permissionsForFields($tier->data_permissions ?? []));
        $hasManagedDataPermissions = $sourcePermissions
            ->intersect(PredictionDataPermissions::allPermissionNames())
            ->isNotEmpty();

        $effectiveDataPermissions = $hasManagedDataPermissions
            ? collect()
            : $mappedDataPermissions;

        return $sourcePermissions
            ->concat($effectiveDataPermissions)
            ->unique()
            ->values()
            ->all();
    }
}
