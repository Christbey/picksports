<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('subscriptions.enforce_tiers', true);
});

it('requires authentication for prediction access debug page', function () {
    $this->get('/debug/prediction-access')
        ->assertRedirect(route('login'));
});

it('renders prediction access debug page for authenticated users', function () {
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

    $this->actingAs($user)
        ->get('/debug/prediction-access?sport=nba')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Debug/PredictionAccess')
            ->where('selectedSport', 'nba')
            ->where('debug.user_id', $user->id)
            ->where('debug.sport', 'nba')
            ->where('debug.tier.role_synced', true)
            ->where('debug.effective_access.spread.effective', true)
            ->where('debug.effective_access.confidence_score.effective', false)
        );
});
