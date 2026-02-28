<?php

use App\Http\Resources\Sports\AbstractPredictionResource;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
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

