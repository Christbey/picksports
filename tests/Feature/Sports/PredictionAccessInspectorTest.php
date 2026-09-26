<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Predictions\PredictionAccessInspector;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('subscriptions.enforce_tiers', true);
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

    $data = app(PredictionAccessInspector::class)->inspect($user, 'nba');
    expect($data['sport'])->toBe('nba')
        ->and($data['user_id'])->toBe($user->id)
        ->and($data['tier']['slug'])->toBe('free')
        ->and($data['tier']['role_synced'])->toBeTrue()
        ->and($data['effective_access']['spread']['effective'])->toBeTrue()
        ->and($data['effective_access']['win_probability']['effective'])->toBeTrue()
        ->and($data['effective_access']['confidence_score']['effective'])->toBeFalse();
});
