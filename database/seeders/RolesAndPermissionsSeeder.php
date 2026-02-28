<?php

namespace Database\Seeders;

use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use App\Support\PredictionDataPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createPermissions();
        $this->createRolesFromTiers();
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
}
