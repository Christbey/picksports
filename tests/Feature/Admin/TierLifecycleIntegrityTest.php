<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function injectTierPermissionFailure(Closure $operation): void
{
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $dispatcher = clone $original;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (preg_match('/\Ainsert into [`"]?role_has_permissions/i', $query->sql)) {
            throw new RuntimeException('Injected role permission write failure');
        }
    });
    $connection->setEventDispatcher($dispatcher);
    try {
        $operation();
    } finally {
        $connection->setEventDispatcher($original);
    }
}

it('refuses a tier rename while preserving the existing role identity and membership', function () {
    $tier = SubscriptionTier::query()->create(['name' => 'Original', 'slug' => 'custom-old']);
    $permission = Permission::findOrCreate('old-paid-signal', 'web');
    $role = Role::findOrCreate('custom-old', 'web');
    $role->givePermissionTo($permission);
    $member = User::factory()->create();
    $member->assignRole($role);
    $this->patchJson(route('admin.tiers.update', $tier), ['name' => 'Renamed', 'slug' => 'custom-new'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    expect($tier->fresh()->slug)->toBe('custom-old')
        ->and($tier->fresh()->name)->toBe('Original')
        ->and($member->fresh()->hasRole('custom-old'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo($permission))->toBeTrue()
        ->and(Role::query()->where('name', 'custom-new')->exists())->toBeFalse();
});

it('refuses role collisions without rewriting independent permissions', function () {
    $permission = Permission::findOrCreate('independent-admin-action', 'web');
    $role = Role::findOrCreate('support-independent', 'web');
    $role->givePermissionTo($permission);
    $this->postJson(route('admin.tiers.store'), ['name' => 'Collision', 'slug' => 'support-independent', 'permissions' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    expect(SubscriptionTier::query()->count())->toBe(0)
        ->and($role->fresh()->hasPermissionTo($permission))->toBeTrue();
});

it('rejects reserved role identifiers before creating access state', function (string $slug) {
    $this->postJson(route('admin.tiers.store'), ['name' => 'Reserved', 'slug' => $slug])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    expect(SubscriptionTier::query()->count())->toBe(0);
})->with(['admin', 'super-admin', 'super_admin', 'founding_user']);

it('creates a new custom tier and role with its intended permissions atomically', function () {
    $permission = Permission::findOrCreate('new-tier-signal', 'web');
    $this->postJson(route('admin.tiers.store'), ['name' => 'New custom', 'slug' => 'new-custom', 'permissions' => [$permission->name]])
        ->assertRedirect(route('admin.tiers.index'));
    expect(SubscriptionTier::query()->where('slug', 'new-custom')->exists())->toBeTrue()
        ->and(Role::findByName('new-custom')->hasPermissionTo($permission))->toBeTrue();
});

it('rolls back a new tier and role when a later permission write fails', function () {
    $permission = Permission::findOrCreate('failure-tier-signal', 'web');
    injectTierPermissionFailure(function () use ($permission): void {
        $this->postJson(route('admin.tiers.store'), ['name' => 'Failing new', 'slug' => 'failing-new', 'permissions' => [$permission->name]])
            ->assertStatus(500);
    });
    expect(SubscriptionTier::query()->where('slug', 'failing-new')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'failing-new')->exists())->toBeFalse();
});

it('rolls back tier overrides and role permissions together when update synchronization fails', function () {
    $old = Permission::findOrCreate('original-tier-signal', 'web');
    $new = Permission::findOrCreate('replacement-tier-signal', 'web');
    $tier = SubscriptionTier::query()->create(['name' => 'Original', 'slug' => 'atomic-update', 'permissions' => [$old->name]]);
    $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
    injectTierPermissionFailure(function () use ($tier, $new): void {
        $this->patchJson(route('admin.tiers.update', $tier), ['name' => 'Changed', 'slug' => 'atomic-update', 'permissions' => [$new->name]])
            ->assertStatus(500);
    });
    expect($tier->fresh()->name)->toBe('Original')
        ->and($tier->fresh()->permissions)->toBe([$old->name])
        ->and($role->fresh()->hasPermissionTo($old))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo($new))->toBeFalse();
});

it('refuses deletion of a role-backed tier and preserves all memberships and permissions', function () {
    $permission = Permission::findOrCreate('retained-tier-signal', 'web');
    $tier = SubscriptionTier::query()->create(['name' => 'Retained', 'slug' => 'retained-custom', 'permissions' => [$permission->name]]);
    $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
    $member = User::factory()->create();
    $member->assignRole($role);
    $this->deleteJson(route('admin.tiers.destroy', $tier))->assertUnprocessable()->assertJsonValidationErrors('tier');
    expect($tier->fresh())->not->toBeNull()
        ->and($member->fresh()->hasRole('retained-custom'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo($permission))->toBeTrue();
    $this->patchJson(route('admin.tiers.update', $tier), ['name' => 'Retired', 'slug' => 'retained-custom', 'is_active' => false, 'permissions' => [$permission->name]])->assertRedirect();
    expect($tier->fresh()->is_active)->toBeFalse();
});

it('refuses deletion of configured default founding and billing identifiers without guessing references', function (array $attributes) {
    $tier = SubscriptionTier::query()->create(['name' => 'Referenced', 'slug' => 'unmanaged-custom', ...$attributes]);
    $this->deleteJson(route('admin.tiers.destroy', $tier))->assertUnprocessable()->assertJsonValidationErrors('tier');
    expect($tier->fresh())->not->toBeNull();
})->with([
    'default' => [['is_default' => true]],
    'configured' => [['slug' => 'free']],
    'founding' => [['slug' => 'premium']],
    'billing' => [['stripe_price_id_monthly' => 'price_uncontacted_reference']],
]);

it('allows removal of an unused unconfigured tier without adopting or deleting independent roles', function () {
    $independent = Role::findOrCreate('manual-independent', 'web');
    $tier = SubscriptionTier::query()->create(['name' => 'Unused', 'slug' => 'unused-custom']);
    $this->deleteJson(route('admin.tiers.destroy', $tier))->assertRedirect(route('admin.tiers.index'));
    expect($tier->fresh())->toBeNull()->and($independent->fresh())->not->toBeNull();
});

it('does not recreate an orphan role from a previously deleted tier object', function () {
    $tier = SubscriptionTier::query()->create(['name' => 'Stale', 'slug' => 'stale-custom']);
    SubscriptionTier::query()->whereKey($tier->id)->delete();
    expect(fn () => app(TierPermissionSyncService::class)->syncTierRolePermissions($tier))->toThrow(ModelNotFoundException::class);
    expect(Role::query()->where('name', 'stale-custom')->exists())->toBeFalse();
});

it('rolls back explicit permission overrides from the permissions page on synchronization failure', function () {
    $old = Permission::findOrCreate('original-page-signal', 'web');
    $new = Permission::findOrCreate('replacement-page-signal', 'web');
    $tier = SubscriptionTier::query()->create(['name' => 'Original', 'slug' => 'atomic-page', 'permissions' => [$old->name]]);
    $role = app(TierPermissionSyncService::class)->syncTierRolePermissions($tier);
    injectTierPermissionFailure(function () use ($tier, $new): void {
        $this->patchJson(route('admin.permissions.tiers.update', $tier), ['permissions' => [$new->name]])->assertStatus(500);
    });
    expect($tier->fresh()->permissions)->toBe([$old->name])
        ->and($role->fresh()->hasPermissionTo($old))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo($new))->toBeFalse();
});

it('protects a configured custom default even without a flag role or other configuration entry', function () {
    config(['subscriptions.default_tier' => 'custom-fallback', 'subscriptions.tiers' => []]);
    $tier = SubscriptionTier::query()->create(['name' => 'Configured fallback', 'slug' => 'custom-fallback', 'is_default' => false]);
    $this->deleteJson(route('admin.tiers.destroy', $tier))->assertUnprocessable()->assertJsonValidationErrors('tier');
    expect($tier->fresh())->not->toBeNull();
});
