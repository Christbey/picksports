<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

it('continues legacy role synchronization without rewriting recorded or founding grants', function () {
    Http::preventStrayRequests();
    config(['billing.entitlements.enabled' => false, 'billing.durable.enabled' => false, 'founding_users.enabled' => true, 'founding_users.role' => 'founding_user']);
    SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true, 'permissions' => [], 'features' => []]);
    $premium = SubscriptionTier::factory()->create(['slug' => 'premium', 'name' => 'Premium', 'is_default' => false, 'permissions' => [], 'features' => []]);
    foreach (['free', 'premium', 'auditor', 'founding_user'] as $name) {
        Role::findOrCreate($name, 'web');
    }
    $managed = User::factory()->create();
    $managed->assignRole('premium');
    DB::table('billing_role_entitlements')->insert(['user_id' => $managed->id, 'role_id' => Role::findByName('premium')->id, 'origin' => 'manual', 'subscription_tier_id' => $premium->id, 'created_at' => now(), 'updated_at' => now()]);
    $before = (array) DB::table('billing_role_entitlements')->sole();
    $legacy = User::factory()->create();
    $legacy->assignRole(['premium', 'auditor']);
    $founding = User::factory()->create();
    $founding->assignRole('founding_user');
    $this->artisan('users:sync-roles')
        ->expectsOutputToContain('Skipped 1 users with recorded entitlement provenance; their grants were preserved.')
        ->assertSuccessful();
    expect($managed->fresh()->getRoleNames()->all())->toBe(['premium'])
        ->and((array) DB::table('billing_role_entitlements')->sole())->toBe($before)
        ->and($legacy->fresh()->getRoleNames()->sort()->values()->all())->toBe(['auditor', 'free'])
        ->and($founding->fresh()->getRoleNames()->all())->toBe(['founding_user']);
    expect(fn () => $managed->fresh()->syncRoleFromTier())->toThrow(LogicException::class, 'Recorded tier grants require explicit billing or manual provenance admission.');
});
