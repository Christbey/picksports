<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('admin can view permissions page with tier data', function () {
    $admin = User::factory()->admin()->create();
    SubscriptionTier::query()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.permissions'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Permissions')
        ->has('tiers', 1)
        ->has('permissions'));
});

test('admin permissions page ensures core sport route permissions exist', function () {
    $admin = User::factory()->admin()->create();

    expect(Permission::query()->where('name', 'view-cfb-predictions')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'view-prediction-spread')->exists())->toBeFalse();

    $this->actingAs($admin)->get(route('admin.permissions'))->assertOk();

    expect(Permission::query()->where('name', 'view-cfb-predictions')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'view-wnba-predictions')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'view-prediction-spread')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'view-prediction-betting-value')->exists())->toBeTrue();
});

test('admin can update tier permissions and role syncs', function () {
    $admin = User::factory()->admin()->create();

    Permission::findOrCreate('view-nba-predictions', 'web');
    Permission::findOrCreate('access-api', 'web');
    Permission::findOrCreate('receive-email-alerts', 'web');

    $tier = SubscriptionTier::query()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'sort_order' => 2,
        'is_active' => true,
        'permissions' => ['receive-email-alerts'],
    ]);

    $role = Role::query()->firstOrCreate(['name' => 'pro']);
    $role->syncPermissions(['receive-email-alerts']);

    $response = $this->actingAs($admin)
        ->from(route('admin.permissions'))
        ->patch(route('admin.permissions.tiers.update', $tier), [
            'permissions' => ['view-nba-predictions', 'access-api'],
        ]);

    $response->assertRedirect(route('admin.permissions'));

    $tier->refresh();
    expect($tier->permissions)->toBe(['view-nba-predictions', 'access-api']);

    $role->refresh();
    expect($role->hasPermissionTo('view-nba-predictions'))->toBeTrue();
    expect($role->hasPermissionTo('access-api'))->toBeTrue();
    expect($role->hasPermissionTo('receive-email-alerts'))->toBeFalse();
});

test('non-admin cannot update tier permissions', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('view-nba-predictions', 'web');

    $tier = SubscriptionTier::query()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.permissions.tiers.update', $tier), [
            'permissions' => ['view-nba-predictions'],
        ])
        ->assertForbidden();
});
