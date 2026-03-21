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

        $guardName = (string) config('auth.defaults.guard', 'web');
        $role = Role::query()->firstOrCreate([
            'name' => $tier->slug,
            'guard_name' => $guardName,
        ]);

        $permissionNames = $this->resolvePermissionNamesForTier($tier);
        $role->syncPermissions($permissionNames);
        $this->syncFoundingRolePermissionsFromTier($tier, $permissionNames, $guardName);

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

        $mappedDataPermissions = collect(PredictionDataPermissions::permissionsForFields($tier->data_permissions ?? []))
            ->intersect($availablePermissions)
            ->values();

        return $sourcePermissions
            ->concat($mappedDataPermissions)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function syncFoundingRolePermissionsFromTier(SubscriptionTier $tier, array $permissionNames, string $guardName): void
    {
        $foundingTierSlug = (string) config('founding_users.tier_slug', 'premium');
        if ($foundingTierSlug === '' || $tier->slug !== $foundingTierSlug) {
            return;
        }

        $foundingRoleName = (string) config('founding_users.role', 'founding_user');
        if ($foundingRoleName === '' || $foundingRoleName === $tier->slug) {
            return;
        }

        $foundingRole = Role::query()->firstOrCreate([
            'name' => $foundingRoleName,
            'guard_name' => $guardName,
        ]);

        $foundingRole->syncPermissions($permissionNames);
    }
}
