<?php

namespace Database\Seeders;

use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use App\Support\PredictionDataPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createPermissions();
        $this->createRolesFromTiers();
        $this->createFoundingUserRole();
        $this->createAdminRole();
    }

    protected function createPermissions(): void
    {
        $permissions = array_merge([
            'view-nba-predictions',
            'view-nfl-predictions',
            'view-cbb-predictions',
            'view-wcbb-predictions',
            'view-mlb-predictions',
            'view-cfb-predictions',
            'view-wnba-predictions',
            'export-predictions',
            'access-api',
            'access-advanced-analytics',
            'receive-email-alerts',
            'access-priority-support',
            'trigger-alerts',
            'view-alert-stats',
        ], PredictionDataPermissions::allPermissionNames());

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->command->info('Permissions created successfully');
    }

    protected function createRolesFromTiers(): void
    {
        $tierPermissionSyncService = app(TierPermissionSyncService::class);
        $tiers = SubscriptionTier::active()->orderBy('sort_order')->get();

        foreach ($tiers as $tier) {
            $role = $tierPermissionSyncService->syncTierRolePermissions($tier);
            $permissions = $role->permissions->pluck('name')->values()->all();

            $this->command->info("Role '{$tier->slug}' created with ".count($permissions).' permissions');
        }
    }

    protected function createAdminRole(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminRole->givePermissionTo(['trigger-alerts', 'view-alert-stats']);

        $this->command->info('Admin role created with alert permissions');
    }

    protected function createFoundingUserRole(): void
    {
        if (! config('founding_users.enabled', false) || (int) config('founding_users.limit', 0) < 1) {
            return;
        }

        $roleName = (string) config('founding_users.role', 'founding_user');
        $tierSlug = (string) config('founding_users.tier_slug', 'premium');
        $guardName = config('auth.defaults.guard', 'web');

        $tier = SubscriptionTier::query()->where('slug', $tierSlug)->first();
        if (! $tier) {
            $this->command->warn("Founding role skipped: tier '{$tierSlug}' not found.");

            return;
        }

        $tierPermissionSyncService = app(TierPermissionSyncService::class);
        $foundingRole = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => $guardName,
        ]);
        $foundingRole->syncPermissions($tierPermissionSyncService->resolvePermissionNamesForTier($tier));

        $this->command->info("Founding role '{$roleName}' created from '{$tierSlug}' tier permissions.");
    }
}
