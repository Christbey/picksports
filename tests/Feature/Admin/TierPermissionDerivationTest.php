<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
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
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

test('tier update preserves explicit permissions managed from admin permissions page', function () {
    $admin = User::factory()->admin()->create();

    $tier = SubscriptionTier::query()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'sort_order' => 2,
        'is_active' => true,
        'permissions' => ['trigger-alerts', 'access-api', 'view-nfl-predictions'],
        'features' => [
            'sports_access' => ['NFL'],
            'export_predictions' => false,
            'api_access' => true,
            'advanced_analytics' => false,
            'email_alerts' => false,
            'priority_support' => false,
        ],
    ]);

    Role::query()->firstOrCreate(['name' => $tier->slug])->syncPermissions($tier->permissions);

    $response = $this->actingAs($admin)->patch(route('admin.tiers.update', $tier), [
        'name' => 'Pro',
        'slug' => 'pro',
        'description' => 'Updated',
        'price_monthly' => 29.99,
        'price_yearly' => 299.99,
        'features' => [
            'predictions_per_day' => null,
            'historical_data_days' => 365,
            'sports_access' => ['NBA', 'MLB'],
            'export_predictions' => true,
            'api_access' => false,
            'advanced_analytics' => true,
            'email_alerts' => true,
            'priority_support' => false,
        ],
        'predictions_limit' => 25,
        'team_metrics_limit' => 10,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $response->assertRedirect(route('admin.tiers.index'));

    $tier->refresh();
    expect($tier->permissions)->toBe(['trigger-alerts', 'access-api', 'view-nfl-predictions']);

    $role = Role::query()->where('name', $tier->slug)->firstOrFail();
    expect($role->hasPermissionTo('view-nfl-predictions'))->toBeTrue();
    expect($role->hasPermissionTo('access-api'))->toBeTrue();
    expect($role->hasPermissionTo('trigger-alerts'))->toBeTrue();
});
