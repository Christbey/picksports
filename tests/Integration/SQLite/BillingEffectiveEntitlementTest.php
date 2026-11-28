<?php

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingRoleEntitlementEvent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingAuthoritativeGateway;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\BillingRoleEntitlements;
use App\Services\Billing\BillingWebhookAdmission;
use App\Support\UserTierResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/../../Fixtures/billing-effective-entitlements.php';

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['inertia.ssr.enabled' => false, 'billing.durable.enabled' => true, 'billing.entitlements.enabled' => true,
        'billing.account.platform_id' => 'acct_entitlement_synthetic', 'billing.account.livemode' => false,
        'billing.account.api_origin' => 'https://api.stripe.com', 'subscriptions.enforce_tiers' => true,
        'subscriptions.tier_bypass_user_ids' => [], 'founding_users.enabled' => false]);
    Route::middleware(['web', 'auth', 'permission:view-nba-predictions'])
        ->get('/__entitlement-permission-proof', fn () => response()->json(['ok' => true]));
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
});

afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('denies canonical-derived web permissions at exact expiry without another webhook', function () {
    [$user, $end] = canonicalEntitlementUser();
    $this->actingAs($user)->getJson('/__entitlement-permission-proof')->assertOk();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->fresh()->subscribed())->toBeFalse();
    $this->actingAs($user->fresh())->getJson('/__entitlement-permission-proof')->assertForbidden();
});

it('denies canonical-derived API permissions at exact expiry without another webhook', function () {
    [$user, $end] = canonicalEntitlementUser();
    Sanctum::actingAs($user);
    $this->getJson('/api/v2/sports/nba/teams')->assertOk();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->fresh()->subscribed())->toBeFalse();
    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/v2/sports/nba/teams')->assertForbidden();
});

it('refuses ambiguous preexisting same-tier grants without reclassifying or projecting them', function () {
    [$user, , $receipt] = canonicalEntitlementUser('unknown');
    expect($receipt->status)->toBe('pending')
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('attention')
        ->and(BillingCustomerReconciliation::query()->sole()->next_attempt_at)->toBeNull()
        ->and($user->subscriptions()->count())->toBe(0)
        ->and(DB::table('billing_role_entitlements')->count())->toBe(0)
        ->and(BillingReconciliationAttempt::query()->sole()->failure_category)->toBe('entitlement_role_origin_unknown')
        ->and($user->hasPermissionTo('view-nba-predictions'))->toBeTrue();
});

it('preserves explicit manual overlap and direct permissions across canonical expiry', function () {
    [$user, $end] = canonicalEntitlementUser('manual');
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->fresh()->subscribed())->toBeFalse()
        ->and($user->hasPermissionTo('view-nba-predictions'))->toBeTrue()
        ->and(DB::table('billing_role_entitlements')->sole()->origin)->toBe('manual');
    $this->actingAs($user)->getJson('/__entitlement-permission-proof')->assertOk();
});

it('enforces recorded derived grants with admission off and on a reused cached user', function () {
    [$user, $end] = canonicalEntitlementUser();
    expect($user->subscriptionTier()->slug)->toBe('entitlement_synthetic')
        ->and($user->can('view-nba-predictions'))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name'))->toContain('view-nba-predictions');
    config(['billing.entitlements.enabled' => false]);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeFalse()
        ->and($user->can('view-nba-predictions'))->toBeFalse()
        ->and($user->getAllPermissions()->pluck('name'))->not->toContain('view-nba-predictions')
        ->and($user->subscriptionTier()->slug)->toBe('free');
    $user->givePermissionTo('view-nba-predictions');
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeTrue();
});

it('retains unobserved legacy role compatibility only with durable billing off', function () {
    config(['billing.entitlements.enabled' => false, 'billing.durable.enabled' => false]);
    $user = User::factory()->create(['stripe_id' => 'cus_unobserved_legacy']);
    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_unobserved_legacy',
        'stripe_status' => 'active', 'stripe_price' => 'price_entitlement_synthetic', 'quantity' => 1]);
    $user->syncRoleFromTier();
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeTrue()
        ->and(DB::table('billing_role_entitlements')->count())->toBe(0);
});

it('lets explicit manual admission classify an ambiguous role without revoking reserved grants', function () {
    [$user] = canonicalEntitlementUser('unknown');
    $admin = Role::findOrCreate('admin', 'web');
    $user->assignRole($admin);
    $tier = SubscriptionTier::query()->where('slug', 'entitlement_synthetic')->sole();
    app(BillingRoleEntitlements::class)->grantManualTier($user, $tier);
    expect($user->fresh()->hasRole('admin'))->toBeTrue()
        ->and(DB::table('billing_role_entitlements')->sole()->origin)->toBe('manual');
});

it('replaces only recorded derived tier roles and removes paid access on authoritative cancellation', function () {
    [$user, , , $observation] = canonicalEntitlementUser();
    $tier = SubscriptionTier::factory()->create(['slug' => 'replacement_synthetic', 'name' => 'Replacement',
        'stripe_price_id_monthly' => 'price_replacement_synthetic', 'permissions' => [], 'features' => [], 'data_permissions' => []]);
    $unrelated = Role::findOrCreate('independent_synthetic', 'web');
    $user->assignRole($unrelated);
    $observation['subscriptions'][0]['items'][0]['price'] = 'price_replacement_synthetic';
    expect(reconcileEntitlementObservation($user, $observation, 'evt_entitlement_replace'))->toBeTrue()
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('replacement_synthetic'))->toBeTrue()
        ->and($user->fresh()->hasRole('independent_synthetic'))->toBeTrue();
    $observation['subscriptions'][0]['status'] = 'canceled';
    $observation['subscriptions'][0]['cancel_at_period_end'] = false;
    $observation['subscriptions'][0]['ended_at'] = now()->getTimestamp();
    expect(reconcileEntitlementObservation($user, $observation, 'evt_entitlement_cancel'))->toBeTrue()
        ->and($user->fresh()->hasRole('replacement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue()
        ->and($user->fresh()->hasRole('independent_synthetic'))->toBeTrue();
});

it('preserves reserved founding grants and explicit direct permissions after derived expiry', function () {
    [$user, $end] = canonicalEntitlementUser();
    config(['founding_users.enabled' => true, 'founding_users.role' => 'founding_user', 'founding_users.tier_slug' => 'entitlement_synthetic']);
    $founding = Role::findOrCreate('founding_user', 'web');
    $founding->givePermissionTo('view-nba-predictions');
    $user->assignRole($founding);
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->fresh()->hasPermissionTo('view-nba-predictions'))->toBeTrue()
        ->and($user->fresh()->subscriptionTier()->slug)->toBe('entitlement_synthetic');
});

it('rebuilds wildcard permission indexes across expiry without removing direct wildcard grants', function () {
    config(['permission.enable_wildcard_permission' => true]);
    [$user, $end] = canonicalEntitlementUser();
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeTrue();
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($end));
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeFalse();
    Permission::findOrCreate('*', 'web');
    $user->givePermissionTo('*');
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeTrue();
});

it('retains immutable provenance audit through ordinary quiet numeric and delete APIs', function (string $operation) {
    canonicalEntitlementUser();
    $receipt = BillingRoleEntitlementEvent::query()->sole();
    $before = $receipt->getAttributes();
    expect(fn () => match ($operation) {
        'save' => $receipt->update(['reason' => 'tampered']),
        'quiet' => $receipt->updateQuietly(['reason' => 'tampered']),
        'numeric' => $receipt->incrementQuietly('role_id'),
        'delete' => $receipt->delete(),
        'deleteQuietly' => $receipt->deleteQuietly(),
    })->toThrow(LogicException::class);
    expect($receipt->fresh()->getAttributes())->toBe($before);
})->with(['save', 'quiet', 'numeric', 'delete', 'deleteQuietly']);

it('rolls back provenance role and subscription changes when audit insertion fails', function () {
    $connection = DB::connection();
    $property = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $before = $property->getValue($connection);
    $connection->beforeExecuting(function (string $sql): void {
        if (preg_match('/^\s*insert\s+into\s+[`"]?billing_role_entitlement_events/i', $sql)) {
            throw new RuntimeException('Synthetic provenance audit storage failure.');
        }
    });
    try {
        canonicalEntitlementUser(null, false);
    } finally {
        $property->setValue($connection, $before);
    }
    $user = User::query()->sole();
    expect($user->subscriptions()->count())->toBe(0)
        ->and($user->roles()->count())->toBe(0)
        ->and(DB::table('billing_role_entitlements')->count())->toBe(0)
        ->and(BillingRoleEntitlementEvent::count())->toBe(0);
});

it('does not reinterpret tier deactivation as cancellation of an existing paid subscription', function () {
    [$user] = canonicalEntitlementUser();
    SubscriptionTier::query()->where('slug', 'entitlement_synthetic')->update(['is_active' => false]);
    expect($user->hasPermissionTo('view-nba-predictions'))->toBeTrue()
        ->and($user->subscriptionTier()->slug)->toBe('entitlement_synthetic');
});

it('honors explicit admin tier downgrades while retaining independent grants and an immutable actor audit', function () {
    [$user, , , $observation] = canonicalEntitlementUser('manual');
    $admin = User::factory()->admin()->create();
    $independent = Role::findOrCreate('independent_admin_fixture', 'web');
    $user->assignRole($independent);
    $this->actingAs($admin)->post('/admin/subscriptions/'.$user->id.'/assign-tier', ['tier_slug' => 'free'])->assertRedirect();
    expect($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue()
        ->and($user->fresh()->hasRole('independent_admin_fixture'))->toBeTrue()
        ->and(app(UserTierResolver::class)->resolveTierSlug($user->fresh()))->toBe('free');
    $event = BillingRoleEntitlementEvent::query()->orderByDesc('id')->first();
    expect($event->actor_user_id)->toBe($admin->id)
        ->and($event->actor_origin)->toBe('manual_operator')->and($event->after_grant['origin'])->toBe('manual');
    expect(reconcileEntitlementObservation($user, $observation, 'evt_manual_downgrade_retained'))->toBeTrue()
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue();
});

it('admits proved customer closure with intentional identity cleanup and a derived free tier', function () {
    [$user, , , $observation] = canonicalEntitlementUser();
    $event = ['id' => 'evt_entitlement_customer_deleted', 'type' => 'customer.deleted', 'created' => now()->getTimestamp(),
        'livemode' => false, 'data' => ['object' => ['id' => $user->stripe_id, 'deleted' => true, 'livemode' => false]]];
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $observation['customer_deleted'] = true;
    $observation['subscriptions'] = [];
    $observation['deletion_proof'] = ['event_id' => $event['id'], 'account_id' => $observation['account_id'],
        'livemode' => false, 'customer_id' => $user->stripe_id, 'customer_object' => 'customer', 'deleted' => true];
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn($observation);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue()
        ->and($user->fresh()->stripe_id)->toBeNull()
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue()
        ->and($user->fresh()->subscriptionTier()->slug)->toBe('free');
});

it('keeps manual removal dry-run first and refuses a mixed invalid scope before any change', function () {
    [$user] = canonicalEntitlementUser('manual');
    $service = app(BillingRoleEntitlements::class);
    $before = BillingRoleEntitlementEvent::count();
    $this->artisan('billing:clear-manual-tier-override', ['--user' => [(string) $user->id], '--reason' => 'Explicit synthetic operator decision'])->assertSuccessful();
    $this->artisan('billing:clear-manual-tier-override', ['--user' => [(string) $user->id, '999999999'], '--reason' => 'Explicit synthetic operator decision', '--apply' => true])->assertExitCode(2);
    expect($service->manualOverrideTier($user)->slug)->toBe('entitlement_synthetic')
        ->and(BillingRoleEntitlementEvent::count())->toBe($before);
});

it('removes only explicit manual override with audit tombstone and no provider or reconciliation dispatch', function () {
    [$user] = canonicalEntitlementUser('manual');
    Bus::fake();
    $before = BillingRoleEntitlementEvent::count();
    $this->artisan('billing:clear-manual-tier-override', ['--user' => [(string) $user->id], '--reason' => 'Explicit synthetic operator decision', '--apply' => true])
        ->expectsOutputToContain('applied=1 changed=1 failed=0')->assertSuccessful();
    expect(app(BillingRoleEntitlements::class)->manualOverrideTier($user))->toBeNull()
        ->and(DB::table('billing_role_entitlements')->sole()->origin)->toBe('revoked')
        ->and(BillingRoleEntitlementEvent::count())->toBe($before + 1);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('refuses manual override changes while a charge-bearing intent remains unresolved even after lease expiry', function (string $kind, string $phase, string $operation) {
    [$user] = canonicalEntitlementUser('manual');
    $intent = BillingMutationIntent::query()->create([
        'operation_uuid' => (string) Str::uuid(), 'subject_user_id' => $user->id, 'user_id' => $user->id,
        'kind' => $kind, 'policy' => 'synthetic-local-guard', 'request_hash' => hash('sha256', 'synthetic-local-guard'),
        'requested_at' => BillingClock::now(),
    ]);
    BillingMutationState::query()->create(['subject_user_id' => $user->id, 'user_id' => $user->id,
        'active_intent_id' => $intent->id, 'status' => $phase, 'lease_expires_at' => now()->subHour()]);
    $before = DB::table('billing_role_entitlements')->sole();
    $auditCount = BillingRoleEntitlementEvent::count();
    $service = app(BillingRoleEntitlements::class);
    expect(fn () => $operation === 'clear'
        ? $service->clearManualOverride($user, 'Explicit synthetic operator request')
        : $service->grantManualTier($user, SubscriptionTier::query()->where('slug', 'free')->sole()))
        ->toThrow(ValidationException::class);
    expect((array) DB::table('billing_role_entitlements')->sole())->toBe((array) $before)
        ->and(BillingRoleEntitlementEvent::count())->toBe($auditCount)
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeTrue();
    Http::assertNothingSent();
})->with([
    ['checkout', 'pending', 'grant'], ['checkout', 'pending', 'clear'],
    ['swap', 'awaiting_reconciliation', 'grant'], ['swap', 'awaiting_reconciliation', 'clear'],
    ['checkout_guard', 'possible_send', 'grant'], ['checkout_guard', 'possible_send', 'clear'],
    ['legacy_swap', 'attention', 'grant'], ['legacy_swap', 'attention', 'clear'],
]);

it('refuses mismatched durable admission flags before canonical paid grants can become unclassified', function () {
    config(['billing.entitlements.enabled' => false]);
    [$user, , $receipt] = canonicalEntitlementUser(null, false);
    expect($user->subscriptions()->count())->toBe(0)
        ->and($user->roles()->count())->toBe(0)
        ->and(DB::table('billing_role_entitlements')->count())->toBe(0)
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('attention')
        ->and(BillingReconciliationAttempt::query()->sole()->failure_category)->toBe('entitlement_provenance_required');
    expect(fn () => app(BillingRoleEntitlements::class)->assertCanonicalAdmission($user))->toThrow(BillingProviderFailure::class);
});

it('continues recorded canonical reconciliation with admission off without laundering manual grants', function () {
    [$user, , , $observation] = canonicalEntitlementUser();
    config(['billing.entitlements.enabled' => false]);
    $observation['subscriptions'][0]['status'] = 'canceled';
    $observation['subscriptions'][0]['cancel_at_period_end'] = false;
    $observation['subscriptions'][0]['ended_at'] = now()->getTimestamp();
    expect(reconcileEntitlementObservation($user, $observation, 'evt_recorded_off_reconcile'))->toBeTrue()
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue()
        ->and(DB::table('billing_role_entitlements')->sole()->origin)->toBe('derived');
});

it('allows explicitly noncharge legacy management without dropping the manual override', function (string $kind) {
    [$user] = canonicalEntitlementUser('manual');
    $intent = BillingMutationIntent::query()->create([
        'operation_uuid' => (string) Str::uuid(), 'subject_user_id' => $user->id, 'user_id' => $user->id,
        'kind' => $kind, 'policy' => 'synthetic-local-guard', 'request_hash' => hash('sha256', 'synthetic-noncharge'),
        'requested_at' => BillingClock::now(),
    ]);
    BillingMutationState::query()->create(['subject_user_id' => $user->id, 'user_id' => $user->id,
        'active_intent_id' => $intent->id, 'status' => 'possible_send']);
    app(BillingRoleEntitlements::class)->grantManualTier($user, SubscriptionTier::query()->where('slug', 'free')->sole());
    expect($user->fresh()->hasRole('free'))->toBeTrue()
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBeFalse();
})->with(['legacy_cancel', 'legacy_resume']);
