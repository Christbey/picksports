<?php

use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('legacy data_permissions are mapped to real permission names when resolving tier permissions', function () {
    foreach ([
        'view-nba-predictions',
        'view-prediction-spread',
        'view-prediction-win-probability',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $tier = new SubscriptionTier([
        'permissions' => ['view-nba-predictions'],
        'data_permissions' => ['spread', 'win_probability'],
    ]);

    $resolved = app(TierPermissionSyncService::class)->resolvePermissionNamesForTier($tier);

    expect($resolved)->toContain('view-nba-predictions');
    expect($resolved)->toContain('view-prediction-spread');
    expect($resolved)->toContain('view-prediction-win-probability');
});

