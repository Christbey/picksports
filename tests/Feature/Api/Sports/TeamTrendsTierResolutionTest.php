<?php

use App\Models\NBA\Team;
use App\Models\SubscriptionTier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function seedTrendTiers(): void
{
    SubscriptionTier::query()->create([
        'name' => 'Free',
        'slug' => 'free',
        'description' => 'Default tier',
        'features' => ['predictions_per_day' => 5],
        'permissions' => [],
        'data_permissions' => ['spread'],
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    SubscriptionTier::query()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'description' => 'Basic tier',
        'features' => ['predictions_per_day' => 25],
        'permissions' => [],
        'data_permissions' => ['spread', 'win_probability'],
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

it('uses default tier slug for trends when authenticated user has no tier role', function () {
    seedTrendTiers();
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertOk()
        ->assertJsonPath('user_tier', 'free');
});

it('uses synced tier role as effective trends tier for authenticated users', function () {
    seedTrendTiers();
    Role::findOrCreate('basic', 'web');
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('basic');
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertOk()
        ->assertJsonPath('user_tier', 'basic');
});
