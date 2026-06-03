<?php

use App\Models\User;
use App\Support\PredictionFieldAccess;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('allows all prediction fields when tier enforcement is disabled', function () {
    config()->set('subscriptions.enforce_tiers', false);

    $user = User::factory()->create();

    expect(app(PredictionFieldAccess::class)->canViewField($user, 'betting_value'))->toBeTrue();
});

it('requires mapped field permissions when tier enforcement is enabled', function () {
    config()->set('subscriptions.enforce_tiers', true);

    Permission::findOrCreate('view-prediction-betting-value', 'web');
    $user = User::factory()->create();

    expect(app(PredictionFieldAccess::class)->canViewField($user, 'betting_value'))->toBeFalse();

    $user->givePermissionTo('view-prediction-betting-value');

    expect(app(PredictionFieldAccess::class)->canViewField($user, 'betting_value'))->toBeTrue();
});
