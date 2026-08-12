<?php

use App\Http\Resources\Sports\AbstractPredictionResource;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('subscriptions.enforce_tiers', true);
});

test('prediction data fields are gated by mapped spatie permissions', function () {
    Permission::findOrCreate('view-prediction-spread', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('view-prediction-spread');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $resource = new class((object) []) extends AbstractPredictionResource
    {
        public function toArray($request): array
        {
            return [];
        }

        public function canView(Request $request, string $field): bool
        {
            return $this->hasTierPermission($request, $field);
        }
    };

    expect($resource->canView($request, 'spread'))->toBeTrue();
    expect($resource->canView($request, 'win_probability'))->toBeFalse();
});

test('prediction data fields are granted by tier-derived role permissions without direct user permission assignment', function () {
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

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $resource = new class((object) []) extends AbstractPredictionResource
    {
        public function toArray($request): array
        {
            return [];
        }

        public function canView(Request $request, string $field): bool
        {
            return $this->hasTierPermission($request, $field);
        }
    };

    expect($resource->canView($request, 'spread'))->toBeTrue();
    expect($resource->canView($request, 'win_probability'))->toBeTrue();
    expect($resource->canView($request, 'confidence_score'))->toBeFalse();
});
