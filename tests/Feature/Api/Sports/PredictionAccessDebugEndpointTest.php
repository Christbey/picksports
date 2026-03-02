<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires sanctum auth for prediction access debug endpoint', function () {
    $this->getJson('/api/v1/nba/debug/prediction-access')->assertUnauthorized();
});

it('returns effective prediction access derived from tier data permissions', function () {
    Permission::findOrCreate('view-prediction-spread', 'web');
    Permission::findOrCreate('view-prediction-win-probability', 'web');

    SubscriptionTier::query()->create([
        'name' => 'Free',
        'slug' => 'free',
        'description' => 'Default tier',
        'features' => ['predictions_per_day' => 5],
        'permissions' => [],
        'data_permissions' => ['spread', 'win_probability'],
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create();
    $user->syncRoleFromTier();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/nba/debug/prediction-access')
        ->assertOk()
        ->assertJsonPath('data.sport', 'nba')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.tier.slug', 'free')
        ->assertJsonPath('data.tier.role_synced', true)
        ->assertJsonPath('data.effective_access.spread.effective', true)
        ->assertJsonPath('data.effective_access.win_probability.effective', true)
        ->assertJsonPath('data.effective_access.confidence_score.effective', false);
});
