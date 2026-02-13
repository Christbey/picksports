<?php

namespace Database\Seeders;

use App\Models\SubscriptionTier;
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
        $permissions = [
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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $this->command->info('Permissions created successfully');
    }

    protected function createRolesFromTiers(): void
    {
        $tiers = SubscriptionTier::active()->orderBy('sort_order')->get();

        foreach ($tiers as $tier) {
            $role = Role::firstOrCreate(['name' => $tier->slug]);

            $permissions = $this->getPermissionsForTier($tier);

            $role->syncPermissions($permissions);

            $this->command->info("Role '{$tier->slug}' created with ".count($permissions).' permissions');
        }
    }

    protected function getPermissionsForTier(SubscriptionTier $tier): array
    {
        $permissions = [];

        if (isset($tier->features['sports_access']) && is_array($tier->features['sports_access'])) {
            foreach ($tier->features['sports_access'] as $sport) {
                $permissions[] = 'view-'.strtolower($sport).'-predictions';
            }
        }

        if ($tier->features['export_predictions'] ?? false) {
            $permissions[] = 'export-predictions';
        }

        if ($tier->features['api_access'] ?? false) {
            $permissions[] = 'access-api';
        }

        if ($tier->features['advanced_analytics'] ?? false) {
            $permissions[] = 'access-advanced-analytics';
        }

        if ($tier->features['email_alerts'] ?? false) {
            $permissions[] = 'receive-email-alerts';
        }

        if ($tier->features['priority_support'] ?? false) {
            $permissions[] = 'access-priority-support';
        }

        return $permissions;
    }

    protected function createAdminRole(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $adminRole->givePermissionTo(['trigger-alerts', 'view-alert-stats']);

        $this->command->info('Admin role created with alert permissions');
    }
}
