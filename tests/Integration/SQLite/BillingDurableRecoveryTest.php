<?php

use App\Http\Controllers\WebhookController;
use App\Jobs\Billing\ReconcileBillingCustomer;
use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingAuthoritativeGateway;
use App\Services\Billing\BillingCanonicalWriteContext;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingDatabaseBoundary;
use App\Services\Billing\BillingJobHandoff;
use App\Services\Billing\BillingOperatorAdmission;
use App\Services\Billing\BillingPermissionCacheRepair;
use App\Services\Billing\BillingProjectionWriter;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\BillingReconciliationCoordinator;
use App\Services\Billing\BillingRecoveryScanner;
use App\Services\Billing\BillingRoleEntitlements;
use App\Services\Billing\BillingTime;
use App\Services\Billing\BillingWebhookAdmission;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config([
        'founding_users.enabled' => false,
        'billing.durable.enabled' => true, 'billing.entitlements.enabled' => true,
        'billing.account.platform_id' => 'acct_readiness_synthetic',
        'billing.account.livemode' => false,
        'billing.account.api_origin' => 'https://api.stripe.com',
    ]);
    SubscriptionTier::factory()->create(['name' => 'Free', 'slug' => 'free', 'is_default' => true, 'permissions' => [], 'features' => []]);
    SubscriptionTier::factory()->create([
        'name' => 'Billing synthetic', 'slug' => 'billing_synthetic', 'stripe_price_id_monthly' => 'price_billing_synthetic',
        'permissions' => [], 'features' => [],
    ]);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

/** Existing rows intentionally predate durable admission; setup does not bypass a production write guard. */
function billingExistingSubscription(User $user, array $attributes)
{
    $enabled = config('billing.durable.enabled');
    config(['billing.durable.enabled' => false]);
    try {
        return $user->subscriptions()->create($attributes);
    } finally {
        config(['billing.durable.enabled' => $enabled]);
    }
}

function durableBillingEvent(string $eventId, int $created, string $type = 'customer.subscription.updated'): array
{
    return [
        'id' => $eventId, 'type' => $type, 'created' => $created, 'livemode' => false,
        'data' => ['object' => [
            'id' => 'sub_billing_synthetic', 'customer' => 'cus_billing_synthetic', 'livemode' => false,
            'status' => 'active', 'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => 'si_billing_synthetic', 'price' => ['id' => 'price_billing_synthetic', 'product' => 'prod_billing_synthetic'], 'quantity' => 1,
            ]]],
        ]],
    ];
}

it('durably admits supported events without applying an unordered event body immediately', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    app(WebhookController::class)->handleCustomerSubscriptionUpdated(durableBillingEvent('evt_billing_pending', 200));

    expect($user->fresh()->subscriptions()->count())->toBe(0);
    expect(BillingWebhookReceipt::query()->sole()->status)->toBe('pending')
        ->and(BillingCustomerReconciliation::query()->sole()->generation)->toBe(1);
});

it('replays identical admission without increasing generation or applying subscription writes', function () {
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $event = durableBillingEvent('evt_billing_duplicate', 100);
    $admission = app(BillingWebhookAdmission::class);
    $first = $admission->admit($event, $event['type']);
    $duplicate = $admission->admit($event, $event['type']);
    expect($duplicate->id)->toBe($first->id)
        ->and(BillingWebhookReceipt::query()->count())->toBe(1)
        ->and(BillingCustomerReconciliation::query()->sole()->generation)->toBe(1);
});

it('retains conflicting event evidence under the original customer without deleting its receipt', function (bool $differentCustomer) {
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $event = durableBillingEvent('evt_billing_conflict', 100);
    $admission = app(BillingWebhookAdmission::class);
    $receipt = $admission->admit($event, $event['type']);
    $hash = $receipt->payload_hash;
    $event['data']['object'][$differentCustomer ? 'customer' : 'status'] = $differentCustomer ? 'cus_conflicting_synthetic' : 'canceled';
    expect(fn () => $admission->admit($event, $event['type']))->toThrow(HttpException::class, 'conflicts');
    expect($receipt->fresh()->payload_hash)->toBe($hash)
        ->and($receipt->fresh()->customer_id)->toBe('cus_billing_synthetic')
        ->and(BillingCustomerReconciliation::query()->count())->toBe(1)
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('attention')
        ->and(BillingCustomerReconciliation::query()->sole()->generation)->toBe(2)
        ->and(BillingReconciliationAttempt::query()->sole()->status)->toBe('conflict');
})->with([false, true]);

it('retains unknown and ambiguous customers pending without changing entitlements', function (int $users) {
    if ($users > 0) {
        User::factory()->count($users)->create(['stripe_id' => 'cus_billing_synthetic']);
    }
    $event = durableBillingEvent('evt_billing_unlinked', 100);
    app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $expected = $users === 0 ? 'pending_linkage' : 'attention';
    expect(BillingWebhookReceipt::query()->sole()->status)->toBe($expected)
        ->and(BillingCustomerReconciliation::query()->sole()->user_id)->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
})->with([0, 2]);

it('validates account mode type and provider origin before any durable write', function (string $defect) {
    $event = durableBillingEvent('evt_billing_invalid', 100);
    match ($defect) {
        'event account' => $event['account'] = 'acct_other_synthetic',
        'event mode' => $event['livemode'] = true,
        'object mode' => $event['data']['object']['livemode'] = true,
        'event type' => $event['type'] = 'invoice.paid',
        'context absent' => config(['billing.account.platform_id' => null]),
        'origin mismatch' => config(['billing.account.api_origin' => 'https://unapproved.example']),
    };
    expect(fn () => app(BillingWebhookAdmission::class)->admit($event, 'customer.subscription.updated'))->toThrow(HttpException::class);
    expect(BillingWebhookReceipt::query()->count())->toBe(0)
        ->and(BillingCustomerReconciliation::query()->count())->toBe(0);
})->with(['event account', 'event mode', 'object mode', 'event type', 'context absent', 'origin mismatch']);

it('admits customer deletion for reconciliation without immediately clearing local identity', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $event = durableBillingEvent('evt_customer_deleted', 100, 'customer.deleted');
    $event['data']['object'] = ['id' => $user->stripe_id, 'deleted' => true, 'livemode' => false];
    app(WebhookController::class)->handleCustomerDeleted($event);
    expect($user->fresh()->stripe_id)->toBe('cus_billing_synthetic')
        ->and(BillingWebhookReceipt::query()->sole()->event_type)->toBe('customer.deleted')
        ->and(BillingWebhookReceipt::query()->sole()->subscription_id)->toBeNull();
});

it('bounds direct event depth and bytes before hashing or writing', function (bool $deep) {
    $event = durableBillingEvent('evt_billing_bounded', 100);
    if ($deep) {
        $nested = [];
        for ($i = 0; $i < 66; $i++) {
            $nested = ['nested' => $nested];
        }
        $event['extra'] = $nested;
    } else {
        $event['extra'] = str_repeat('x', (int) config('billing.maximum_event_bytes'));
    }
    expect(fn () => app(BillingWebhookAdmission::class)->admit($event, $event['type']))->toThrow(HttpException::class);
    expect(BillingWebhookReceipt::query()->count())->toBe(0);
})->with([false, true]);

it('refuses inherited transactions before writing any billing receipt', function () {
    DB::beginTransaction();
    try {
        $event = durableBillingEvent('evt_billing_outer', 100);
        expect(fn () => app(BillingWebhookAdmission::class)->admit($event, $event['type']))->toThrow(LogicException::class);
        expect(BillingWebhookReceipt::query()->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});

it('protects admission and terminal attempt history against stale quiet and numeric model mutations', function () {
    $event = durableBillingEvent('evt_billing_history', 100);
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    expect(fn () => $receipt->updateQuietly(['payload_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class);
    $receipt->refresh();
    expect(fn () => $receipt->deleteQuietly())->toThrow(LogicException::class);
    expect(fn () => $receipt->incrementQuietly('generation'))->toThrow(LogicException::class);
    $attempt = BillingReconciliationAttempt::query()->create([
        'reconciliation_id' => $receipt->reconciliation_id, 'generation' => 1, 'lease_sequence' => 1,
        'owner_token' => (string) Str::uuid(), 'kind' => 'reconcile', 'status' => 'running', 'started_at' => now(),
    ]);
    BillingReconciliationAttempt::query()->findOrFail($attempt->id)->update(['status' => 'succeeded', 'completed_at' => now()]);
    expect(fn () => $attempt->updateQuietly(['details' => ['stale' => true]]))->toThrow(LogicException::class);
    expect(fn () => $attempt->decrementQuietly('generation'))->toThrow(LogicException::class);
    expect(fn () => $attempt->deleteQuietly())->toThrow(LogicException::class);
});

it('limits original webhook bytes before vendor signature parsing and keeps small unsigned events forbidden', function () {
    config(['cashier.webhook.secret' => 'whsec_synthetic', 'billing.maximum_event_bytes' => 100]);
    $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], str_repeat('x', 101))
        ->assertStatus(413);
    $this->postJson('/stripe/webhook', ['type' => 'unsupported.test'])->assertForbidden();
    expect(BillingWebhookReceipt::query()->count())->toBe(0);
});

it('keeps provider event identifiers case sensitive and does not bind a differently cased legacy customer', function () {
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $admission = app(BillingWebhookAdmission::class);
    $lower = durableBillingEvent('evt_billing_case', 100);
    $upper = durableBillingEvent('evt_billing_Case', 100);
    $admission->admit($lower, $lower['type']);
    $admission->admit($upper, $upper['type']);
    expect(BillingWebhookReceipt::query()->count())->toBe(2)
        ->and(BillingCustomerReconciliation::query()->sole()->generation)->toBe(2);
    $differentCustomer = durableBillingEvent('evt_billing_other_customer', 100);
    $differentCustomer['data']['object']['customer'] = 'cus_billing_Synthetic';
    $receipt = $admission->admit($differentCustomer, $differentCustomer['type']);
    expect($receipt->status)->toBe('pending_linkage')
        ->and(BillingCustomerReconciliation::query()->whereKey($receipt->reconciliation_id)->sole()->user_id)->toBeNull()
        ->and(BillingCustomerReconciliation::query()->count())->toBe(2);
});

it('does not trust a non Stripe origin merely because both configurations agree', function () {
    $original = Cashier::$apiBaseUrl;
    Cashier::$apiBaseUrl = 'https://unapproved.example';
    config(['billing.account.api_origin' => 'https://unapproved.example']);
    try {
        $event = durableBillingEvent('evt_billing_bad_origin', 100);
        expect(fn () => app(BillingWebhookAdmission::class)->admit($event, $event['type']))->toThrow(HttpException::class);
        expect(BillingWebhookReceipt::query()->count())->toBe(0);
    } finally {
        Cashier::$apiBaseUrl = $original;
    }
});

function billingLeaseFixture(): array
{
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $event = durableBillingEvent('evt_billing_lease', 100);
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $service = app(BillingReconciliationCoordinator::class);

    return [$user, $receipt, $event, $service, $service->claim($receipt->reconciliation_id)];
}

function billingLeaseObservation(): array
{
    return ['account_id' => 'acct_readiness_synthetic', 'livemode' => false,
        'customer_id' => 'cus_billing_synthetic', 'customer_deleted' => false,
        'fetch_started_at' => now()->getTimestamp(), 'fetch_completed_at' => now()->getTimestamp(),
        'subscriptions' => [['id' => 'sub_billing_synthetic', 'customer_id' => 'cus_billing_synthetic',
            'livemode' => false, 'type' => 'default', 'status' => 'canceled', 'created' => 1700000000,
            'trial_end' => null, 'cancel_at' => null, 'canceled_at' => null, 'ended_at' => null,
            'cancel_at_period_end' => false, 'items' => []]]];
}

it('claims once and commits a bounded receipt and local projection together', function () {
    [$user, $receipt, , $service, $attempt] = billingLeaseFixture();
    expect($service->claim($receipt->reconciliation_id))->toBeNull();
    $applied = $service->commit($attempt, billingLeaseObservation(), function ($current): array {
        DB::table('users')->where('id', $current->id)->update(['name' => 'Projection applied']);

        return ['before' => ['name' => 'before'], 'after' => ['name' => 'Projection applied']];
    });
    expect($applied)->toBeTrue()->and($user->fresh()->name)->toBe('Projection applied')
        ->and($receipt->fresh()->status)->toBe('applied')
        ->and($attempt->fresh()->status)->toBe('succeeded')
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('idle');
    expect($service->commit($attempt, billingLeaseObservation(), fn () => test()->fail('Duplicate projection ran')))->toBeFalse();
});

it('fences an observation when a newer event arrives during fetch and preserves the replacement owner', function () {
    [, $receipt, , $service, $first] = billingLeaseFixture();
    $newer = durableBillingEvent('evt_billing_newer', 200);
    app(BillingWebhookAdmission::class)->admit($newer, $newer['type']);
    expect($service->commit($first, billingLeaseObservation(), fn () => test()->fail('Stale projection ran')))->toBeFalse()
        ->and($first->fresh()->status)->toBe('stale')->and($receipt->fresh()->status)->toBe('pending');
    $replacement = $service->claim($receipt->reconciliation_id);
    expect($replacement->generation)->toBe(2)
        ->and($service->fail($first, 'late_failure'))->toBeFalse()
        ->and(BillingCustomerReconciliation::query()->sole()->owner_token)->toBe($replacement->owner_token);
});

it('preserves integrity attention when a conflicting event arrives during fetch', function () {
    [, $receipt, $event, $service, $owner] = billingLeaseFixture();
    $conflict = $event;
    $conflict['data']['object']['status'] = 'canceled';
    expect(fn () => app(BillingWebhookAdmission::class)->admit($conflict, $conflict['type']))->toThrow(HttpException::class);
    expect($service->commit($owner, billingLeaseObservation(), fn () => test()->fail('Conflicted projection ran')))->toBeFalse()
        ->and($service->fail($owner, 'late_failure'))->toBeFalse();
    app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $legitimate = durableBillingEvent('evt_after_conflict', 300);
    app(BillingWebhookAdmission::class)->admit($legitimate, $legitimate['type']);
    expect(BillingCustomerReconciliation::query()->sole()->status)->toBe('attention')
        ->and(BillingCustomerReconciliation::query()->sole()->failure_category)->toBe('event_hash_conflict')
        ->and($receipt->fresh()->status)->toBe('pending')
        ->and(BillingReconciliationAttempt::query()->where('kind', 'admission_conflict')->count())->toBe(1)
        ->and($service->claim($receipt->reconciliation_id))->toBeNull();
});

it('rejects expired or reclaimed observations before invoking projection', function () {
    [, $receipt, , $service, $first] = billingLeaseFixture();
    $this->travel(120)->seconds();
    $replacement = $service->claim($receipt->reconciliation_id);
    expect($replacement->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe('stale')
        ->and($service->commit($first, billingLeaseObservation(), fn () => test()->fail('Expired projection ran')))->toBeFalse()
        ->and($service->fail($first, 'late_failure'))->toBeFalse()
        ->and(BillingCustomerReconciliation::query()->sole()->owner_token)->toBe($replacement->owner_token);
});

it('rolls back business and receipt changes on projection failure or lease expiry during local work', function (bool $expires) {
    [$user, $receipt, , $service, $owner] = billingLeaseFixture();
    $before = $user->name;
    expect(fn () => $service->commit($owner, billingLeaseObservation(), function ($current) use ($expires): array {
        DB::table('users')->where('id', $current->id)->update(['name' => 'Must roll back']);
        if ($expires) {
            test()->travel(120)->seconds();
        } else {
            throw new RuntimeException('Injected projection failure');
        }

        return ['before' => [], 'after' => []];
    }))->toThrow(RuntimeException::class);
    expect($user->fresh()->name)->toBe($before)->and($receipt->fresh()->status)->toBe('pending')
        ->and($owner->fresh()->status)->toBe('running');
})->with([false, true]);

it('backs off provider failures and retains pending events at bounded retry exhaustion', function () {
    config(['billing.maximum_attempts' => 1]);
    [, $receipt, , $service, $owner] = billingLeaseFixture();
    expect($service->fail($owner, 'provider_timeout'))->toBeTrue()
        ->and($receipt->fresh()->status)->toBe('pending')
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('attention')
        ->and(BillingCustomerReconciliation::query()->sole()->failure_category)->toBe('retry_exhausted')
        ->and($service->claim($receipt->reconciliation_id))->toBeNull();
});

it('refuses over-cap distinct subscription targets without consuming a provider attempt', function () {
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    for ($i = 0; $i < 5; $i++) {
        $event = durableBillingEvent('evt_target_'.$i, 100);
        $event['data']['object']['id'] = 'sub_target_'.$i;
        $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    }
    expect(app(BillingReconciliationCoordinator::class)->claim($receipt->reconciliation_id))->toBeNull()
        ->and(BillingCustomerReconciliation::query()->sole()->failure_category)->toBe('target_limit_exceeded')
        ->and(BillingReconciliationAttempt::query()->count())->toBe(0)
        ->and(BillingWebhookReceipt::query()->where('status', 'pending')->count())->toBe(5);
});

it('retains durable cache repair when a false-returning adapter leaves the permission key present', function () {
    [, $receipt] = billingLeaseFixture();
    $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
    $state->update(['cache_invalidation_pending' => true, 'cache_generation' => 1, 'cache_lease_sequence' => 1]);
    $store = new class extends ArrayStore
    {
        public function forget($key)
        {
            return false;
        }
    };
    $cache = new Repository($store);
    $cache->put('billing-permission-key', ['retained' => 'old-permissions'], 600);
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->cacheKey = 'billing-permission-key';
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturnUsing(fn () => $cache->forget('billing-permission-key'));
    $registrar->shouldReceive('getCacheRepository')->once()->andReturn($cache);
    expect((new BillingPermissionCacheRepair($registrar))->repair($state))->toBeFalse()
        ->and($state->fresh()->cache_invalidation_pending)->toBeTrue()
        ->and($cache->get('billing-permission-key'))->toBe(['retained' => 'old-permissions']);
});

it('accepts verified cache absence but cannot clear a later committed invalidation marker', function (bool $newerCommit) {
    [, $receipt] = billingLeaseFixture();
    $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
    $state->update(['cache_invalidation_pending' => true, 'cache_generation' => 1, 'cache_lease_sequence' => 1]);
    $cache = new Repository(new ArrayStore);
    $registrar = Mockery::mock(PermissionRegistrar::class);
    $registrar->cacheKey = 'billing-permission-key';
    $registrar->shouldReceive('forgetCachedPermissions')->once()->andReturnUsing(function () use ($state, $cache, $newerCommit) {
        if ($newerCommit) {
            BillingCustomerReconciliation::query()->whereKey($state->id)->update(['cache_lease_sequence' => 2]);

            return true;
        }

        return $cache->forget('billing-permission-key');
    });
    if (! $newerCommit) {
        $registrar->shouldReceive('getCacheRepository')->once()->andReturn($cache);
    }
    expect((new BillingPermissionCacheRepair($registrar))->repair($state))->toBe(! $newerCommit)
        ->and($state->fresh()->cache_invalidation_pending)->toBe($newerCommit);
})->with([false, true]);

it('does not reactivate a canceled local projection from an older active event body', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, [
        'type' => 'default', 'stripe_id' => 'sub_billing_synthetic', 'stripe_status' => 'canceled',
        'stripe_price' => 'price_billing_synthetic', 'quantity' => 1, 'ends_at' => now()->subDay(),
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_billing_synthetic', 'stripe_product' => 'prod_billing_synthetic', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1,
    ]);
    $user->syncRoleFromTier();
    app(WebhookController::class)->handleCustomerSubscriptionUpdated(durableBillingEvent('evt_billing_older', 100));

    expect($subscription->fresh()->stripe_status)->toBe('canceled')
        ->and($user->fresh()->subscribed())->toBeFalse()
        ->and($user->fresh()->hasRole('billing_synthetic'))->toBeFalse();
});

/** Establish a genuine prior canonical-derived grant before exercising later cancellation/rollback. */
function billingPriorDerivedGrant(User $user): void
{
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_prior_derived_fixture', 50), 'customer.subscription.updated');
    $observation = billingLeaseObservation();
    $observation['subscriptions'][0]['status'] = 'active';
    $observation['subscriptions'][0]['items'] = [['id' => 'si_billing_synthetic', 'price' => 'price_billing_synthetic',
        'product' => 'prod_billing_synthetic', 'quantity' => 1, 'current_period_end' => BillingClock::now()->addDay()->getTimestamp()]];
    $leases = app(BillingReconciliationCoordinator::class);
    $attempt = $leases->claim($receipt->reconciliation_id);
    expect($attempt)->not->toBeNull();
    expect($leases->begin($attempt))->toBeTrue();
    expect($leases->commit($attempt, $observation, app(BillingProjectionWriter::class)->apply(...)))->toBeTrue();
    expect(DB::table('billing_role_entitlements')->where('user_id', $user->id)->where('origin', 'derived')->count())->toBe(1);
}

it('reconciles a stale active trigger to current canceled provider truth and retains only normalized evidence', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    $subscription->items()->create(['stripe_id' => 'si_billing_synthetic', 'stripe_product' => 'prod_billing_synthetic',
        'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    billingPriorDerivedGrant($user);
    expect($user->fresh()->hasRole('billing_synthetic'))->toBeTrue();
    $event = durableBillingEvent('evt_current_projection', 100);
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn([...billingLeaseObservation(), 'private_raw_body' => 'never_store_me']);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue()
        ->and($subscription->fresh()->stripe_status)->toBe('canceled')->and($user->fresh()->subscribed())->toBeFalse()
        ->and($user->fresh()->hasRole('billing_synthetic'))->toBeFalse()->and($receipt->fresh()->status)->toBe('applied');
    $attempt = BillingReconciliationAttempt::query()->orderByDesc('id')->firstOrFail();
    expect($attempt->before_projection['subscriptions'][0]['stripe_status'])->toBe('active')
        ->and($attempt->after_projection['subscriptions'][0]['stripe_status'])->toBe('canceled')
        ->and($attempt->observation_evidence['subscriptions'][0]['created'])->toBe(1700000000)
        ->and(json_encode($attempt->observation_evidence))->not->toContain('never_store_me');
    expect(fn () => $attempt->forceFill(['observation_evidence' => []])->saveQuietly())->toThrow(LogicException::class);
});

it('projects the formerly nonrepresentable provider instant and keeps exact bounded epoch evidence', function () {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-02T12:00:00Z'));
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    $event = durableBillingEvent('evt_fold_projection', 100);
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $epochs = array_map(fn ($value) => CarbonImmutable::parse($value)->getTimestamp(), ['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z']);
    $unrepresentable = collect($epochs)->first(function ($epoch) {
        try {
            BillingTime::sql($epoch);

            return false;
        } catch (BillingProviderFailure) {
            return true;
        }
    });
    expect($unrepresentable)->toBeInt();
    $observation = billingLeaseObservation();
    $observation['subscriptions'][0]['ended_at'] = $unrepresentable;
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn($observation);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue()
        ->and($subscription->fresh()->stripe_status)->toBe('canceled')->and($subscription->fresh()->ends_at->getTimestamp())->toBe($unrepresentable)
        ->and($receipt->fresh()->status)->toBe('applied');
    $attempt = BillingReconciliationAttempt::query()->sole();
    expect($attempt->failure_category)->toBeNull()
        ->and($attempt->observation_evidence['subscriptions'][0]['ended_at'])->toBe($unrepresentable)
        ->and($attempt->before_projection['subscriptions'][0]['stripe_status'])->toBe('active')
        ->and($attempt->after_projection['subscriptions'][0]['billing_end_epoch'])->toBe($unrepresentable);
    $this->travelBack();
});

it('rolls back subscription item and role writes when local projection fails before receipt completion', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    $item = $subscription->items()->create(['stripe_id' => 'si_billing_synthetic', 'stripe_product' => 'prod_billing_synthetic',
        'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    billingPriorDerivedGrant($user);
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_local_failure', 100), 'customer.subscription.updated');
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn(billingLeaseObservation());
    $writer = new class(app(BillingDatabaseBoundary::class)) extends BillingProjectionWriter
    {
        public function apply(User $user, BillingCustomerReconciliation $state, BillingReconciliationAttempt $attempt, array $observation): array
        {
            parent::apply($user, $state, $attempt, $observation);
            throw new RuntimeException('Injected failure after SQL and role writes');
        }
    };
    $service = new BillingReconciler(app(BillingReconciliationCoordinator::class), $gateway, $writer,
        app(BillingPermissionCacheRepair::class));
    expect($service->run($receipt->reconciliation_id))->toBeFalse()
        ->and($subscription->fresh()->stripe_status)->toBe('active')->and($subscription->fresh()->ends_at)->toBeNull()
        ->and($item->fresh()->stripe_price)->toBe('price_billing_synthetic')
        ->and($user->fresh()->hasRole('billing_synthetic'))->toBeTrue()
        ->and($receipt->fresh()->status)->toBe('pending')
        ->and(BillingReconciliationAttempt::query()->orderByDesc('id')->firstOrFail()->failure_category)->toBe('unexpected_failure');
});

function billingAsyncQueue(): void
{
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database',
        'queue.connections.database.queue' => 'default', 'queue.connections.database.retry_after' => 2100,
        'queue.worker_contracts.default.timeout' => 120]);
}

it('skips synchronous HTTP handoff before claiming any provider work', function () {
    Bus::fake();
    config(['queue.default' => 'sync']);
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    app(WebhookController::class)->handleCustomerSubscriptionUpdated(durableBillingEvent('evt_sync_handoff', 100));
    expect(BillingReconciliationAttempt::query()->count())->toBe(0)
        ->and(BillingCustomerReconciliation::query()->sole()->status)->toBe('pending');
    Bus::assertNothingDispatched();
});

it('claims one default-worker handoff and does not enqueue a duplicate receipt or live lease twice', function () {
    billingAsyncQueue();
    Bus::fake();
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $event = durableBillingEvent('evt_async_handoff', 100);
    app(WebhookController::class)->handleCustomerSubscriptionUpdated($event);
    app(WebhookController::class)->handleCustomerSubscriptionUpdated($event);
    expect(app(BillingJobHandoff::class)->dispatch(BillingCustomerReconciliation::query()->sole()->id))->toBeFalse();
    Bus::assertDispatchedTimes(ReconcileBillingCustomer::class, 1);
    Bus::assertDispatched(ReconcileBillingCustomer::class,
        fn ($job) => $job->queue === 'default' && $job->connection === 'database' && $job->timeout === 90);
    expect(BillingReconciliationAttempt::query()->count())->toBe(1);
});

it('establishes execution budget once after queue waiting and refuses duplicate or expired starts', function () {
    [, , , $leases, $attempt] = billingLeaseFixture();
    $this->travel(110)->seconds();
    expect($leases->begin($attempt))->toBeTrue();
    $expiry = BillingCustomerReconciliation::query()->sole()->lease_expires_at;
    expect($expiry->getTimestamp() - now()->getTimestamp())->toBe(120);
    $this->travel(10)->seconds();
    expect($leases->begin($attempt))->toBeFalse()
        ->and(BillingCustomerReconciliation::query()->sole()->lease_expires_at->getTimestamp())->toBe($expiry->getTimestamp());
});

it('recovers a crash after execution start without allowing the old owner to start again', function () {
    [, $receipt, , $leases, $first] = billingLeaseFixture();
    expect($leases->begin($first))->toBeTrue();
    $this->travel(121)->seconds();
    expect($leases->begin($first))->toBeFalse();
    $second = $leases->claim($receipt->reconciliation_id);
    expect($second->id)->not->toBe($first->id)->and($first->fresh()->status)->toBe('stale')
        ->and($leases->begin($second))->toBeTrue()->and($leases->begin($first))->toBeFalse();
});

it('retains the lease after an ambiguous enqueue failure and recovers it through a later scanner pass', function () {
    billingAsyncQueue();
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_lost_enqueue', 100), 'customer.subscription.updated');
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic enqueue uncertainty'));
    expect(app(BillingJobHandoff::class)->dispatch($receipt->reconciliation_id))->toBeFalse()
        ->and($receipt->fresh()->status)->toBe('pending')->and(BillingReconciliationAttempt::query()->sole()->status)->toBe('running');
    $this->travel(121)->seconds();
    Bus::fake();
    $scan = app(BillingRecoveryScanner::class)->scan(10, true);
    expect($scan['enqueued'])->toBe(1)->and(BillingReconciliationAttempt::query()->where('status', 'stale')->count())->toBe(1);
    Bus::assertDispatchedTimes(ReconcileBillingCustomer::class, 1);
});

it('keeps scanner dry runs side effect free and excludes integrity attention', function () {
    billingAsyncQueue();
    Bus::fake();
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_scan_preview', 100), 'customer.subscription.updated');
    $scan = app(BillingRecoveryScanner::class)->scan(1);
    expect($scan['due'])->toBe([$receipt->reconciliation_id])->and($scan['enqueued'])->toBe(0)
        ->and(BillingReconciliationAttempt::query()->count())->toBe(0);
    BillingCustomerReconciliation::query()->whereKey($receipt->reconciliation_id)->update(['status' => 'attention', 'failure_category' => 'event_hash_conflict']);
    expect(app(BillingRecoveryScanner::class)->scan(1, true)['due'])->toBe([]);
    Bus::assertNothingDispatched();
});

it('fences duplicate and wrong-token job deliveries before a second provider observation', function () {
    [, $receipt, , , $attempt] = billingLeaseFixture();
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn(billingLeaseObservation());
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    $reconciler = app(BillingReconciler::class);
    expect($reconciler->runAttempt($attempt->id, 'wrong-token'))->toBeFalse()
        ->and($reconciler->runAttempt($attempt->id, $attempt->owner_token))->toBeTrue()
        ->and($reconciler->runAttempt($attempt->id, $attempt->owner_token))->toBeFalse()
        ->and($receipt->fresh()->status)->toBe('applied');
});

it('persists an exact operational lease lifetime across both repeated-hour instants and the fold boundary', function (string $instant) {
    $timezone = date_default_timezone_get();
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    try {
        $epoch = CarbonImmutable::parse($instant)->getTimestamp();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch));
        [, $receipt, , $leases, $attempt] = billingLeaseFixture();
        $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
        expect($state->lease_expires_at->getTimestamp() - $epoch)->toBe(120)
            ->and($attempt->fresh()->started_at->getTimestamp())->toBe($epoch)
            ->and($state->created_at->getTimestamp())->toBe($epoch);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 119));
        expect($state->fresh()->lease_expires_at->isFuture())->toBeTrue();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 121));
        expect($state->fresh()->lease_expires_at->isFuture())->toBeFalse();
    } finally {
        $this->travelBack();
        date_default_timezone_set($timezone);
    }
})->with(['2026-11-01T06:10:00Z', '2026-11-01T07:10:00Z', '2026-11-01T06:59:30Z']);

function billingOperatorUser(string $suffix = 'synthetic'): array
{
    $user = User::factory()->create(['stripe_id' => 'cus_operator_'.$suffix]);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_operator_'.$suffix,
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);

    return [$user, $subscription];
}

it('previews operator recovery without creating a receipt intent state or provider work', function () {
    [$user] = billingOperatorUser();
    $this->artisan('billing:reconcile', ['--user' => [(string) $user->id]])->assertSuccessful();
    expect(BillingWebhookReceipt::query()->count())->toBe(0)->and(BillingReconciliationAttempt::query()->count())->toBe(0)
        ->and(BillingCustomerReconciliation::query()->count())->toBe(0);
});

it('admits honest immutable operator intent without fabricating a Stripe event', function () {
    [$user, $subscription] = billingOperatorUser();
    $service = app(BillingOperatorAdmission::class);
    $plan = $service->plan([], [$user->id], []);
    $intent = $service->admit($plan[0], 'Review current entitlement after queue recovery');
    $state = BillingCustomerReconciliation::query()->sole();
    expect($intent->kind)->toBe('operator_request')->and($intent->status)->toBe('requested')
        ->and($intent->targets)->toBe([$subscription->stripe_id])->and($state->operator_request_id)->toBe($intent->id)
        ->and(BillingWebhookReceipt::query()->count())->toBe(0);
    expect(fn () => $intent->forceFill(['targets' => []])->saveQuietly())->toThrow(LogicException::class);
    expect(fn () => $intent->fresh()->incrementQuietly('generation'))->toThrow(LogicException::class);
    $owner = app(BillingReconciliationCoordinator::class)->claim($state->id);
    expect($owner->targets)->toBe([$subscription->stripe_id])->and($owner->details['operator_request_id'])->toBe($intent->id);
});

it('rejects an invalid whole operator scope before admitting any initial customer', function () {
    [$user] = billingOperatorUser();
    $this->artisan('billing:reconcile', ['--user' => [(string) $user->id, '999999999'], '--reason' => 'Explicit recovery', '--apply' => true])
        ->assertExitCode(2);
    expect(BillingCustomerReconciliation::query()->count())->toBe(0)->and(BillingReconciliationAttempt::query()->count())->toBe(0);
});

it('requires explicit audited retry exhaustion reset and refuses conflict or live-lease replacements', function () {
    [$user] = billingOperatorUser();
    $state = BillingCustomerReconciliation::query()->create(['account_id' => 'acct_readiness_synthetic', 'livemode' => false,
        'customer_id' => $user->stripe_id, 'user_id' => $user->id, 'status' => 'attention', 'failure_category' => 'retry_exhausted', 'attempts' => 8]);
    $service = app(BillingOperatorAdmission::class);
    expect(fn () => $service->plan([$state->id], [], []))->toThrow(InvalidArgumentException::class);
    $intent = $service->admit($service->plan([$state->id], [], [], true)[0], 'Retry after verified provider recovery');
    expect($intent->details['retry_exhaustion_reset'])->toBeTrue()->and($intent->details['prior_attempt_count'])->toBe(8)
        ->and($state->fresh()->attempts)->toBe(0);
    app(BillingReconciliationCoordinator::class)->claim($state->id);
    expect(fn () => $service->plan([$state->id], [], [], true))->toThrow(InvalidArgumentException::class);
    $state->refresh()->update(['owner_token' => null, 'lease_expires_at' => null, 'status' => 'attention', 'failure_category' => 'event_hash_conflict']);
    expect(fn () => $service->plan([$state->id], [], [], true))->toThrow(InvalidArgumentException::class);
});

it('rechecks exact customer binding after operator preflight before writing any intent', function () {
    [$user] = billingOperatorUser();
    $service = app(BillingOperatorAdmission::class);
    $plan = $service->plan([], [$user->id], [])[0];
    DB::table('users')->where('id', $user->id)->update(['stripe_id' => 'cus_changed_after_preflight']);
    expect($user->fresh()->stripe_id)->toBe('cus_changed_after_preflight');
    expect(fn () => $service->admit($plan, 'Verify changed binding'))->toThrow(RuntimeException::class)
        ->and(BillingCustomerReconciliation::query()->count())->toBe(0)->and(BillingReconciliationAttempt::query()->count())->toBe(0);
});

it('allows a later captured Stripe generation to complete the same older operator intent', function () {
    [$user, $subscription] = billingOperatorUser();
    $service = app(BillingOperatorAdmission::class);
    $intent = $service->admit($service->plan([], [$user->id], [])[0], 'Reconcile current subscription');
    $event = durableBillingEvent('evt_after_operator', 100);
    $event['data']['object']['id'] = $subscription->stripe_id;
    $event['data']['object']['customer'] = $user->stripe_id;
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $leases = app(BillingReconciliationCoordinator::class);
    $owner = $leases->claim($receipt->reconciliation_id);
    $observation = billingLeaseObservation();
    $observation['customer_id'] = $user->stripe_id;
    $observation['subscriptions'][0]['id'] = $subscription->stripe_id;
    $observation['subscriptions'][0]['customer_id'] = $user->stripe_id;
    expect($owner->generation)->toBeGreaterThan($intent->generation)
        ->and($leases->commit($owner, $observation, fn () => ['before' => [], 'after' => []]))->toBeTrue()
        ->and(BillingCustomerReconciliation::query()->sole()->operator_request_id)->toBeNull()
        ->and($intent->fresh()->status)->toBe('requested')->and($receipt->fresh()->status)->toBe('applied');
});

it('does not let an expired old generation clear a newer immutable operator request', function () {
    [$user] = billingOperatorUser();
    $operator = app(BillingOperatorAdmission::class);
    $first = $operator->admit($operator->plan([], [$user->id], [])[0], 'First explicit request');
    $leases = app(BillingReconciliationCoordinator::class);
    $oldOwner = $leases->claim($first->reconciliation_id);
    $this->travel(121)->seconds();
    $next = $operator->admit($operator->plan([], [$user->id], [])[0], 'Replacement after expired queue lease');
    expect($oldOwner->fresh()->status)->toBe('stale')
        ->and($leases->commit($oldOwner, billingLeaseObservation(), fn () => test()->fail('Expired projection ran')))->toBeFalse()
        ->and(BillingCustomerReconciliation::query()->sole()->operator_request_id)->toBe($next->id);
});

it('reports earlier per-customer admissions when a later binding changes after whole-scope preflight', function () {
    billingAsyncQueue();
    [$first] = billingOperatorUser('a');
    [$second] = billingOperatorUser('b');
    Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($second) {
        User::query()->whereKey($second->id)->update(['stripe_id' => 'cus_operator_changed']);

        return null;
    });
    $this->artisan('billing:reconcile', ['--user' => [(string) $first->id, (string) $second->id],
        '--reason' => 'Explicit bounded recovery', '--apply' => true])->expectsOutputToContain('"partial":true')->assertExitCode(1);
    expect(BillingReconciliationAttempt::query()->where('kind', 'operator_request')->count())->toBe(1)
        ->and(BillingCustomerReconciliation::query()->sole()->customer_id)->toBe($first->stripe_id)
        ->and(BillingWebhookReceipt::query()->count())->toBe(0);
});

it('uses a current tombstone rather than an old deletion trigger to decide entitlement removal', function (bool $deleted) {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    $subscription->items()->create(['stripe_id' => 'si_billing_synthetic', 'stripe_product' => 'prod_billing_synthetic',
        'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    billingPriorDerivedGrant($user);
    $event = ['id' => 'evt_authoritative_delete', 'type' => 'customer.deleted', 'created' => 100, 'livemode' => false,
        'data' => ['object' => ['id' => 'cus_billing_synthetic', 'object' => 'customer']]];
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $observation = billingLeaseObservation();
    if ($deleted) {
        $observation['customer_deleted'] = true;
        $observation['subscriptions'] = [];
        $observation['deletion_proof'] = ['event_id' => $event['id'], 'account_id' => $observation['account_id'],
            'livemode' => false, 'customer_id' => $user->stripe_id, 'customer_object' => 'customer', 'deleted' => true];
    } else {
        $observation['subscriptions'][0]['status'] = 'active';
        $observation['subscriptions'][0]['items'] = [['id' => 'si_billing_synthetic', 'price' => 'price_billing_synthetic',
            'product' => 'prod_billing_synthetic', 'quantity' => 1, 'current_period_end' => null]];
    }
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn($observation);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue()
        ->and($user->fresh()->stripe_id)->toBe($deleted ? null : 'cus_billing_synthetic')
        ->and($subscription->fresh()->stripe_status)->toBe($deleted ? 'canceled' : 'active')
        ->and($user->fresh()->hasRole('billing_synthetic'))->toBe(! $deleted)->and($receipt->fresh()->status)->toBe('applied');
})->with([true, false]);

it('rejects incompatible worker execution or visibility budgets before claiming pending billing work', function (array $overrides) {
    billingAsyncQueue();
    config($overrides);
    Bus::fake();
    User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_bad_worker_budget', 100), 'customer.subscription.updated');
    expect(app(BillingJobHandoff::class)->dispatch($receipt->reconciliation_id))->toBeFalse()
        ->and(BillingReconciliationAttempt::query()->count())->toBe(0)
        ->and(BillingCustomerReconciliation::query()->sole()->owner_token)->toBeNull();
    Bus::assertNothingDispatched();
})->with([
    [['queue.worker_contracts.default.timeout' => 90]],
    [['queue.connections.database.retry_after' => 90]],
    [['billing.fetch_budget_seconds' => 0]],
    [['billing.fetch_budget_seconds' => 120]],
]);

it('reconciles both repeated-hour provider instants with exact instance and SQL expiry semantics', function (string $endInstant) {
    $timezone = date_default_timezone_get();
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    try {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T05:30:00Z'));
        $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
        $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
            'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
        $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_exact_canonical', 100), 'customer.subscription.updated');
        $epoch = CarbonImmutable::parse($endInstant)->getTimestamp();
        $observation = billingLeaseObservation();
        $observation['subscriptions'][0] = [...$observation['subscriptions'][0], 'status' => 'trialing',
            'trial_end' => $epoch, 'cancel_at_period_end' => true,
            'items' => [['id' => 'si_billing_synthetic', 'price' => 'price_billing_synthetic', 'product' => 'prod_billing_synthetic',
                'quantity' => 1, 'current_period_end' => $epoch]]];
        $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
        $gateway->shouldReceive('fetch')->once()->andReturn($observation);
        app()->instance(BillingAuthoritativeGateway::class, $gateway);
        expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue();
        expect($subscription->fresh()->ends_at->getTimestamp())->toBe($epoch)
            ->and($subscription->fresh()->trial_ends_at->getTimestamp())->toBe($epoch);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch - 1));
        expect($subscription->fresh()->onGracePeriod())->toBeTrue()
            ->and($subscription->newQuery()->whereKey($subscription->id)->onGracePeriod()->count())->toBe(1)
            ->and($user->fresh()->subscribed())->toBeTrue();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch));
        expect($subscription->fresh()->onTrial())->toBeFalse()
            ->and($subscription->fresh()->hasExpiredTrial())->toBeTrue()
            ->and($subscription->newQuery()->whereKey($subscription->id)->expiredTrial()->count())->toBe(1)
            ->and($subscription->newQuery()->whereKey($subscription->id)->onTrial()->count())->toBe(0);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 1));
        expect($subscription->fresh()->onGracePeriod())->toBeFalse()
            ->and($subscription->newQuery()->whereKey($subscription->id)->onGracePeriod()->count())->toBe(0)
            ->and($user->fresh()->subscribed())->toBeFalse();
    } finally {
        $this->travelBack();
        date_default_timezone_set($timezone);
    }
})->with(['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z']);

it('keeps persisted canonical rows fenced against stale quiet writes deletes and counters with admission disabled', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_billing_synthetic']);
    $subscription = billingExistingSubscription($user, ['type' => 'default', 'stripe_id' => 'sub_billing_synthetic',
        'stripe_status' => 'active', 'stripe_price' => 'price_billing_synthetic', 'quantity' => 1]);
    $stale = $subscription->fresh();
    $receipt = app(BillingWebhookAdmission::class)->admit(durableBillingEvent('evt_guard_canonical', 100), 'customer.subscription.updated');
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn(billingLeaseObservation());
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue();
    $canonical = $subscription->fresh();
    expect($canonical->hasCanonicalProjection())->toBeTrue();
    config(['billing.durable.enabled' => false]);
    expect(fn () => $stale->forceFill(['stripe_status' => 'active', 'ends_at' => null])->saveQuietly())->toThrow(LogicException::class);
    expect(fn () => $stale->fresh()->forceFill(['billing_end_epoch' => null])->saveQuietly())->toThrow(LogicException::class);
    expect(fn () => $stale->deleteQuietly())->toThrow(LogicException::class);
    expect(fn () => $stale->incrementQuietly('quantity'))->toThrow(LogicException::class);
    expect(fn () => $canonical->incrementQuietly('billing_end_epoch'))->toThrow(LogicException::class);
    expect($subscription->fresh()->stripe_status)->toBe('canceled')
        ->and($subscription->fresh()->billing_end_epoch)->toBe($canonical->billing_end_epoch)
        ->and($receipt->fresh()->status)->toBe('applied');
});

it('refuses a stale persisted canonical capability after the execution lease expires', function () {
    [, $receipt, , , $owner] = billingLeaseFixture();
    $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
    $this->travel(121)->seconds();
    expect(fn () => DB::transaction(fn () => app(BillingCanonicalWriteContext::class)->run($state, $owner,
        fn () => test()->fail('Expired canonical capability executed'))))->toThrow(LogicException::class);
});

it('resumes only explicitly classified role-origin attention with immutable operator audit and never auto resets on a manual grant', function () {
    config(['billing.entitlements.enabled' => true]);
    [$user] = billingOperatorUser('classified');
    $tier = SubscriptionTier::query()->where('slug', 'billing_synthetic')->firstOrFail();
    $role = Role::findOrCreate($tier->slug, 'web');
    $user->assignRole($role);
    $state = BillingCustomerReconciliation::query()->create(['account_id' => 'acct_readiness_synthetic', 'livemode' => false,
        'customer_id' => $user->stripe_id, 'user_id' => $user->id, 'status' => 'attention', 'failure_category' => 'entitlement_role_origin_unknown']);
    $admission = app(BillingOperatorAdmission::class);
    expect(fn () => $admission->plan([$state->id], [], [], false, true))->toThrow(BillingProviderFailure::class);
    app(BillingRoleEntitlements::class)->grantManualTier($user, $tier);
    expect($state->fresh()->status)->toBe('attention');
    expect(fn () => $admission->plan([$state->id], [], []))->toThrow(InvalidArgumentException::class);
    $plan = $admission->plan([$state->id], [], [], false, true)[0];
    $intent = $admission->admit($plan, 'Reviewed explicit manual role classification');
    expect($intent->details['classified_role_origin_resume'])->toBeTrue()
        ->and($intent->details['prior_failure_category'])->toBe('entitlement_role_origin_unknown')
        ->and($state->fresh()->status)->toBe('pending');
    $state->refresh()->update(['status' => 'attention', 'failure_category' => 'event_hash_conflict']);
    expect(fn () => $admission->plan([$state->id], [], [], false, true))->toThrow(InvalidArgumentException::class);
});

it('requires explicit configured entitlement admission recovery rather than clearing attention when a flag changes', function () {
    [$user] = billingOperatorUser('provenance');
    $state = BillingCustomerReconciliation::query()->create(['account_id' => 'acct_readiness_synthetic', 'livemode' => false,
        'customer_id' => $user->stripe_id, 'user_id' => $user->id, 'status' => 'attention', 'failure_category' => 'entitlement_provenance_required']);
    $operator = app(BillingOperatorAdmission::class);
    config(['billing.entitlements.enabled' => false]);
    expect(fn () => $operator->plan([$state->id], [], [], false, false, true))->toThrow(BillingProviderFailure::class);
    config(['billing.entitlements.enabled' => true]);
    expect($state->fresh()->status)->toBe('attention');
    expect(fn () => $operator->plan([$state->id], [], []))->toThrow(InvalidArgumentException::class);
    $plan = $operator->plan([$state->id], [], [], false, false, true)[0];
    $intent = $operator->admit($plan, 'Configured reviewed typed entitlement admission');
    expect($intent->details['entitlement_admission_resume'])->toBeTrue()->and($state->fresh()->status)->toBe('pending');
});
