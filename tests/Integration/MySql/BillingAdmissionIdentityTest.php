<?php

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingWebhookReceipt;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingAuthoritativeGateway;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\BillingReconciliationCoordinator;
use App\Services\Billing\BillingWebhookAdmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true, 'billing.account.platform_id' => 'acct_readiness_synthetic',
        'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com']);
});

function mysqlBillingIdentityEvent(string $eventId, string $customerId, string $subscriptionId): array
{
    return ['id' => $eventId, 'type' => 'customer.subscription.updated', 'created' => 100, 'livemode' => false,
        'data' => ['object' => ['id' => $subscriptionId, 'customer' => $customerId, 'livemode' => false]]];
}

it('uses binary new ledger identifiers and exact customer matching despite legacy case insensitive collation', function () {
    User::factory()->create(['stripe_id' => 'cus_legacy_lower']);
    // Reproduce the actual legacy collation boundary explicitly in this private ephemeral schema.
    DB::statement('ALTER TABLE users MODIFY stripe_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL');
    expect(User::query()->where('stripe_id', 'cus_legacy_Lower')->count())->toBe(1);
    $service = app(BillingWebhookAdmission::class);
    $first = mysqlBillingIdentityEvent('evt_identity_lower', 'cus_legacy_lower', 'sub_identity_lower');
    $second = mysqlBillingIdentityEvent('evt_identity_Lower', 'cus_legacy_lower', 'sub_identity_Lower');
    $service->admit($first, $first['type']);
    $service->admit($second, $second['type']);
    expect(BillingWebhookReceipt::query()->where('event_id', 'evt_identity_lower')->count())->toBe(1)
        ->and(BillingWebhookReceipt::query()->where('event_id', 'evt_identity_Lower')->count())->toBe(1)
        ->and(BillingWebhookReceipt::query()->where('subscription_id', 'sub_identity_lower')->count())->toBe(1)
        ->and(BillingWebhookReceipt::query()->where('subscription_id', 'sub_identity_Lower')->count())->toBe(1);
    $variant = mysqlBillingIdentityEvent('evt_other_customer', 'cus_legacy_Lower', 'sub_other');
    $receipt = $service->admit($variant, $variant['type']);
    expect($receipt->status)->toBe('pending_linkage')
        ->and(BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id)->user_id)->toBeNull()
        ->and(BillingCustomerReconciliation::query()->count())->toBe(2);
});

it('keeps new billing operational epochs exact through the Chicago repeated hour on MySQL', function (string $instant) {
    $timezone = date_default_timezone_get();
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    try {
        $epoch = CarbonImmutable::parse($instant)->getTimestamp();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch));
        User::factory()->create(['stripe_id' => 'cus_mysql_fold']);
        $event = mysqlBillingIdentityEvent('evt_mysql_fold', 'cus_mysql_fold', 'sub_mysql_fold');
        $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
        $owner = app(BillingReconciliationCoordinator::class)->claim($receipt->reconciliation_id);
        $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
        expect($state->lease_expires_at->getTimestamp())->toBe($epoch + 120)
            ->and($state->created_at->getTimestamp())->toBe($epoch)->and($owner->fresh()->started_at->getTimestamp())->toBe($epoch);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 119));
        expect($state->fresh()->lease_expires_at->isFuture())->toBeTrue();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 121));
        expect($state->fresh()->lease_expires_at->isFuture())->toBeFalse();
    } finally {
        $this->travelBack();
        date_default_timezone_set($timezone);
    }
})->with(['2026-11-01T06:10:00Z', '2026-11-01T07:10:00Z', '2026-11-01T06:59:30Z']);

it('stores new billing dates as UTC DATETIME independently of changing MySQL session zones', function (string $instant) {
    $timezone = date_default_timezone_get();
    $sessionZone = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    try {
        $epoch = CarbonImmutable::parse($instant)->getTimestamp();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch));
        DB::statement("SET time_zone = '+05:30'");
        User::factory()->create(['stripe_id' => 'cus_mysql_session']);
        $event = mysqlBillingIdentityEvent('evt_mysql_session', 'cus_mysql_session', 'sub_mysql_session');
        $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
        $owner = app(BillingReconciliationCoordinator::class)->claim($receipt->reconciliation_id);
        foreach (['+00:00', '-06:00', '+05:30'] as $zone) {
            DB::statement('SET time_zone = ?', [$zone]);
            $state = BillingCustomerReconciliation::query()->findOrFail($receipt->reconciliation_id);
            expect($state->lease_expires_at->getTimestamp())->toBe($epoch + 120)
                ->and($state->created_at->getTimestamp())->toBe($epoch)
                ->and($owner->fresh()->started_at->getTimestamp())->toBe($epoch)
                ->and($receipt->fresh()->received_at->getTimestamp())->toBe($epoch);
        }
        $columns = DB::table('information_schema.columns')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', ['billing_customer_reconciliations', 'billing_webhook_receipts', 'billing_reconciliation_attempts'])
            ->where(function ($query) {
                $query->where('COLUMN_NAME', 'like', '%_at')->orWhere('COLUMN_NAME', 'lease_expires_at');
            })->get(['COLUMN_NAME', 'DATA_TYPE', 'DATETIME_PRECISION', 'COLUMN_DEFAULT']);
        expect($columns)->not->toBeEmpty();
        foreach ($columns as $column) {
            // provider_created_at is the original integer epoch, not a date column.
            if ($column->COLUMN_NAME === 'provider_created_at') {
                continue;
            }
            expect($column->DATA_TYPE)->toBe('datetime')->and((int) $column->DATETIME_PRECISION)->toBe(6)
                ->and($column->COLUMN_DEFAULT)->toBeNull();
        }
    } finally {
        DB::statement('SET time_zone = ?', [$sessionZone]);
        $this->travelBack();
        date_default_timezone_set($timezone);
    }
})->with(['2026-11-01T06:10:00Z', '2026-11-01T07:10:00Z', '2026-11-01T06:59:30Z']);

it('projects both canonical provider fold instants with exact MySQL instance and SQL entitlement boundaries', function (string $instant) {
    $timezone = date_default_timezone_get();
    $sessionZone = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
    config(['app.timezone' => 'America/Chicago', 'founding_users.enabled' => false, 'billing.durable.enabled' => false]);
    date_default_timezone_set('America/Chicago');
    try {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T05:30:00Z'));
        SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true, 'permissions' => [], 'features' => []]);
        SubscriptionTier::factory()->create(['slug' => 'mysql_epoch', 'name' => 'MySQL epoch', 'stripe_price_id_monthly' => 'price_mysql_epoch', 'permissions' => [], 'features' => []]);
        $user = User::factory()->create(['stripe_id' => 'cus_mysql_canonical']);
        $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_mysql_canonical',
            'stripe_status' => 'active', 'stripe_price' => 'price_mysql_epoch', 'quantity' => 1]);
        config(['billing.durable.enabled' => true]);
        $event = mysqlBillingIdentityEvent('evt_mysql_canonical', $user->stripe_id, $subscription->stripe_id);
        $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
        $epoch = CarbonImmutable::parse($instant)->getTimestamp();
        $observation = ['account_id' => 'acct_readiness_synthetic', 'livemode' => false, 'customer_id' => $user->stripe_id,
            'customer_deleted' => false, 'fetch_started_at' => now()->getTimestamp(), 'fetch_completed_at' => now()->getTimestamp(),
            'subscriptions' => [['id' => $subscription->stripe_id, 'customer_id' => $user->stripe_id, 'livemode' => false,
                'type' => 'default', 'status' => 'trialing', 'created' => 1700000000, 'trial_end' => $epoch,
                'cancel_at' => null, 'canceled_at' => null, 'ended_at' => null, 'cancel_at_period_end' => true,
                'items' => [['id' => 'si_mysql_epoch', 'price' => 'price_mysql_epoch', 'product' => 'prod_mysql_epoch',
                    'quantity' => 1, 'current_period_end' => $epoch]]]]];
        $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
        $gateway->shouldReceive('fetch')->once()->andReturn($observation);
        app()->instance(BillingAuthoritativeGateway::class, $gateway);
        expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBeTrue();
        DB::statement("SET time_zone = '+05:30'");
        expect($subscription->fresh()->ends_at->getTimestamp())->toBe($epoch)
            ->and($subscription->fresh()->trial_ends_at->getTimestamp())->toBe($epoch)
            ->and($receipt->fresh()->status)->toBe('applied');
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch - 1));
        expect($subscription->fresh()->onGracePeriod())->toBeTrue()
            ->and($subscription->newQuery()->whereKey($subscription->id)->onGracePeriod()->count())->toBe(1)
            ->and($user->fresh()->subscribed())->toBeTrue();
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($epoch + 1));
        expect($subscription->fresh()->onGracePeriod())->toBeFalse()
            ->and($subscription->newQuery()->whereKey($subscription->id)->onGracePeriod()->count())->toBe(0)
            ->and($user->fresh()->subscribed())->toBeFalse();
    } finally {
        DB::statement('SET time_zone = ?', [$sessionZone]);
        $this->travelBack();
        date_default_timezone_set($timezone);
    }
})->with(['2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z']);
