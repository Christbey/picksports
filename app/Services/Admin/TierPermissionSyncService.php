<?php

namespace App\Services\Admin;

use App\Models\SubscriptionTier;
use App\Support\PredictionDataPermissions;
use Illuminate\Support\Collection;
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

        $validStoredPermissions = $storedPermissions->intersect($availablePermissions)->values();

        // Backward compatibility: legacy tier permission strings may not match current permission names.
        // If stored permissions exist but none are valid, derive from tier features.
        $sourcePermissions = $validStoredPermissions->isNotEmpty()
            ? $validStoredPermissions
            : $this->derivedPermissionsFromFeatures($tier);

        $mappedDataPermissions = collect(PredictionDataPermissions::permissionsForFields($tier->data_permissions ?? []));
        $hasManagedDataPermissions = $validStoredPermissions
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

    /**
     * @return Collection<int, string>
     */
    private function derivedPermissionsFromFeatures(SubscriptionTier $tier): Collection
    {
        $features = $tier->features ?? [];

        $permissions = collect();

        $sportsAccess = $features['sports_access'] ?? [];
        if (is_array($sportsAccess)) {
            foreach ($sportsAccess as $sport) {
                if (! is_string($sport) || $sport === '') {
                    continue;
                }

                $permissions->push('view-'.strtolower($sport).'-predictions');
            }
        }

        if (($features['export_predictions'] ?? false) === true) {
            $permissions->push('export-predictions');
        }

        if (($features['api_access'] ?? false) === true) {
            $permissions->push('access-api');
        }

        if (($features['advanced_analytics'] ?? false) === true) {
            $permissions->push('access-advanced-analytics');
        }

        if (($features['email_alerts'] ?? false) === true) {
            $permissions->push('receive-email-alerts');
        }

        if (($features['priority_support'] ?? false) === true) {
            $permissions->push('access-priority-support');
        }

        return $permissions->unique()->values();
    }
}
