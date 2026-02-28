<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Route::has('tests.permission.web.missing')) {
        Route::middleware(['web', 'auth', 'permission:tests-missing-permission'])
            ->get('/__tests__/permission/web/missing', fn () => response()->json(['ok' => true]))
            ->name('tests.permission.web.missing');
    }

    if (! Route::has('tests.permission.web.wrong-guard')) {
        Route::middleware(['web', 'auth', 'permission:tests-wrong-guard-permission'])
            ->get('/__tests__/permission/web/wrong-guard', fn () => response()->json(['ok' => true]))
            ->name('tests.permission.web.wrong-guard');
    }

    if (! Route::has('tests.permission.api.missing')) {
        Route::middleware(['api', 'auth:sanctum', 'permission:tests-api-missing-permission'])
            ->get('/__tests__/permission/api/missing', fn () => response()->json(['ok' => true]))
            ->name('tests.permission.api.missing');
    }

    if (! Route::has('tests.permission.api.wrong-guard')) {
        Route::middleware(['api', 'auth:sanctum', 'permission:tests-api-wrong-guard-permission'])
            ->get('/__tests__/permission/api/wrong-guard', fn () => response()->json(['ok' => true]))
            ->name('tests.permission.api.wrong-guard');
    }
});

it('denies web requests with missing permission without throwing server errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/__tests__/permission/web/missing')
        ->assertForbidden()
        ->assertJsonPath('message', "You don't have permission to access this resource. Required permission: tests-missing-permission");
});

it('denies web requests when permission exists under another guard', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('tests-wrong-guard-permission', 'sanctum');

    $this->actingAs($user)
        ->getJson('/__tests__/permission/web/wrong-guard')
        ->assertForbidden()
        ->assertJsonPath('message', "You don't have permission to access this resource. Required permission: tests-wrong-guard-permission");
});

it('denies api requests with missing permission without throwing server errors', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/__tests__/permission/api/missing')
        ->assertForbidden()
        ->assertJsonPath('message', "You don't have permission to access this resource. Required permission: tests-api-missing-permission");
});

it('denies api requests when permission exists under another guard', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Permission::findOrCreate('tests-api-wrong-guard-permission', 'web');

    $this->getJson('/__tests__/permission/api/wrong-guard')
        ->assertForbidden()
        ->assertJsonPath('message', "You don't have permission to access this resource. Required permission: tests-api-wrong-guard-permission");
});
