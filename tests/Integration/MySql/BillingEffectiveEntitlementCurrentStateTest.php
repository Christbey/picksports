<?php

use App\Models\SubscriptionTier;
use App\Services\Billing\BillingRoleEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

require_once __DIR__.'/../../Fixtures/billing-effective-entitlements.php';

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true,
        'billing.account.platform_id' => 'acct_entitlement_synthetic', 'billing.account.livemode' => false,
        'billing.account.api_origin' => 'https://api.stripe.com', 'subscriptions.enforce_tiers' => true,
        'subscriptions.tier_bypass_user_ids' => [], 'founding_users.enabled' => false]);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00Z'));
    foreach (['access-api', 'view-nba-predictions'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true,
        'permissions' => [], 'features' => [], 'data_permissions' => []]);
    SubscriptionTier::factory()->create(['slug' => 'entitlement_synthetic', 'name' => 'Synthetic',
        'stripe_price_id_monthly' => 'price_entitlement_synthetic', 'permissions' => ['access-api', 'view-nba-predictions'],
        'features' => ['api_access' => true, 'sports_access' => ['nba']], 'data_permissions' => []]);
    config(['database.connections.entitlement_writer' => DB::connection()->getConfig()]);
    DB::purge('entitlement_writer');
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('entitlement_writer');
    $this->travelBack();
});

it('observes corrected canonical expiry despite an inherited older repeatable-read subscription snapshot', function () {
    [$user] = canonicalEntitlementUser();
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    $user->load('subscriptions', 'roles.permissions');
    expect($user->subscribed())->toBeTrue();
    DB::connection('entitlement_writer')->table('subscriptions')->where('user_id', $user->id)
        ->update(['billing_end_epoch' => now()->getTimestamp() - 1]); // Explicit trusted synthetic source correction.
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeFalse()
        ->and($user->subscriptionTier()->slug)->toBe('free');
});

it('does not misclassify newly recorded provenance as legacy under an older empty snapshot', function () {
    [$user] = canonicalEntitlementUser();
    $grant = (array) DB::table('billing_role_entitlements')->sole();
    DB::table('billing_role_entitlements')->delete(); // Explicit trusted synthetic pre-admission state.
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    expect(DB::table('billing_role_entitlements')->count())->toBe(0);
    $user->load('roles.permissions', 'subscriptions');
    $writer = DB::connection('entitlement_writer');
    $writer->transaction(function () use ($writer, $grant, $user): void {
        $writer->table('billing_role_entitlements')->insert($grant);
        $writer->table('subscriptions')->where('user_id', $user->id)->update(['billing_end_epoch' => now()->getTimestamp() - 1]);
    });
    expect(app(BillingRoleEntitlements::class)->hasRecorded($user))->toBeTrue()
        ->and($user->hasPermissionTo('view-nba-predictions'))->toBeFalse();
});
