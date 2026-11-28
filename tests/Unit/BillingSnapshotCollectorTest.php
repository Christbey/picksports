<?php

use App\Services\Billing\BillingFetchClock;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingSnapshotCollector;
use App\Services\Http\ApprovedHostGet;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['billing.durable.enabled' => true, 'billing.account.platform_id' => 'acct_synthetic',
        'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com',
        'cashier.secret' => 'synthetic_not_a_real_key']);
    Http::preventStrayRequests();
});

function billingCollectorRequest(): array
{
    return ['account_id' => 'acct_synthetic', 'livemode' => false, 'customer_id' => 'cus_synthetic', 'targets' => ['sub_synthetic']];
}

function billingCollectorSubscription(): array
{
    return ['id' => 'sub_synthetic', 'object' => 'subscription', 'customer' => 'cus_synthetic',
        'livemode' => false, 'status' => 'canceled', 'created' => 1700000000, 'trial_end' => null,
        'cancel_at_period_end' => false, 'items' => ['data' => [], 'has_more' => false]];
}

it('collects current scoped provider truth without returning raw credentials or financial payloads', function () {
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'secret_metadata' => 'must_not_persist'])]);
    $result = app(BillingSnapshotCollector::class)->collect(billingCollectorRequest());
    expect($result['subscriptions'][0]['status'])->toBe('canceled')->and($result['provider_calls'])->toBe(2)
        ->and(json_encode($result))->not->toContain('must_not_persist', 'synthetic_not_a_real_key');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer synthetic_not_a_real_key'));
    Http::assertSentCount(2);
});

it('does not treat missing resources or wrong provider mode as permission to clear known state', function (int $status, bool $mode, string $category) {
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'livemode' => $mode], $status)]);
    try {
        app(BillingSnapshotCollector::class)->collect(billingCollectorRequest());
        test()->fail('Invalid provider evidence must not produce a projection.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe($category);
    }
})->with([[404, false, 'provider_not_found'], [200, true, 'provider_scope_mismatch']]);

it('requires both the mode-bound deletion event and the current customer tombstone', function (bool $deleted) {
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/events/evt_deleted' => Http::response(['id' => 'evt_deleted', 'object' => 'event', 'type' => 'customer.deleted',
            'livemode' => false, 'data' => ['object' => ['id' => 'cus_synthetic', 'object' => 'customer']]]),
        'https://api.stripe.com/v1/customers/cus_synthetic' => Http::response(['id' => 'cus_synthetic', 'object' => 'customer', 'deleted' => $deleted]),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'status' => 'active',
            'items' => ['has_more' => false, 'data' => [['id' => 'si_synthetic', 'price' => ['id' => 'price_synthetic', 'product' => 'prod_synthetic'], 'quantity' => 1]]]])]);
    $result = app(BillingSnapshotCollector::class)->collect([...billingCollectorRequest(), 'customer_deletion_event_id' => 'evt_deleted']);
    expect($result['customer_deleted'])->toBe($deleted)->and($result['deletion_proof'] === null)->toBe(! $deleted)
        ->and($result['subscriptions'])->toHaveCount($deleted ? 0 : 1);
    Http::assertSentCount($deleted ? 3 : 4);
    if (! $deleted) {
        expect($result['subscriptions'][0]['status'])->toBe('active');
    }
})->with([true, false]);

it('enforces the aggregate fetch deadline between calls rather than resetting a per-call budget', function () {
    $clock = new class extends BillingFetchClock
    {
        public float $value = 100;

        public function now(): float
        {
            return $this->value;
        }
    };
    config(['billing.fetch_budget_seconds' => 2]);
    Http::fake(function () use ($clock) {
        $clock->value += 3;

        return Http::response(['object' => 'account', 'id' => 'acct_synthetic']);
    });
    try {
        (new BillingSnapshotCollector(app(ApprovedHostGet::class), $clock))->collect(billingCollectorRequest());
        test()->fail('Expired aggregate budget must stop before another provider read.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe('provider_timeout');
    }
    Http::assertSentCount(1);
});

it('refuses missing or wrong-mode deletion event authority before reading or clearing a customer', function (int $status, bool $mode, string $category) {
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/events/evt_deleted' => Http::response(['id' => 'evt_deleted', 'object' => 'event', 'type' => 'customer.deleted',
            'livemode' => $mode, 'data' => ['object' => ['id' => 'cus_synthetic', 'object' => 'customer']]], $status)]);
    try {
        app(BillingSnapshotCollector::class)->collect([...billingCollectorRequest(), 'customer_deletion_event_id' => 'evt_deleted']);
        test()->fail('Deletion needs current bound authority.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe($category);
    }
    Http::assertSentCount(2);
})->with([[404, false, 'provider_not_found'], [200, true, 'provider_scope_mismatch']]);

function billingCollectorItem(string $suffix): array
{
    return ['id' => 'si_'.$suffix, 'price' => ['id' => 'price_synthetic', 'product' => 'prod_synthetic'], 'quantity' => 1];
}

it('does not fetch a page beyond the admitted complete-inventory page cap', function () {
    config(['billing.maximum_item_pages' => 1]);
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'status' => 'active',
            'items' => ['has_more' => true, 'data' => [billingCollectorItem('first')]]]),
        'https://api.stripe.com/v1/subscription_items?*' => Http::response(['has_more' => false, 'data' => [billingCollectorItem('second')]])]);
    try {
        app(BillingSnapshotCollector::class)->collect(billingCollectorRequest());
        test()->fail('Incomplete inventory must not produce a projection.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe('provider_inventory_incomplete');
    }
    Http::assertSentCount(2);
});

it('collects complete bounded paginated items without losing the initial page', function () {
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'status' => 'active',
            'items' => ['has_more' => true, 'data' => [billingCollectorItem('first')]]]),
        'https://api.stripe.com/v1/subscription_items?*' => Http::response(['has_more' => false, 'data' => [billingCollectorItem('second')]])]);
    $result = app(BillingSnapshotCollector::class)->collect(billingCollectorRequest());
    expect(array_column($result['subscriptions'][0]['items'], 'id'))->toBe(['si_first', 'si_second']);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'starting_after=si_first') && str_contains($request->url(), 'subscription=sub_synthetic'));
});

it('refuses aggregate item overflow and missing cancellation period evidence', function (bool $overflow, string $category) {
    config(['billing.maximum_items' => $overflow ? 1 : 100]);
    Http::fake(['https://api.stripe.com/v1/account' => Http::response(['object' => 'account', 'id' => 'acct_synthetic']),
        'https://api.stripe.com/v1/subscriptions/sub_synthetic' => Http::response([...billingCollectorSubscription(), 'status' => 'active',
            'cancel_at_period_end' => ! $overflow, 'items' => ['has_more' => false,
                'data' => $overflow ? [billingCollectorItem('one'), billingCollectorItem('two')] : [billingCollectorItem('one')]]])]);
    try {
        app(BillingSnapshotCollector::class)->collect(billingCollectorRequest());
        test()->fail('Incomplete or oversized provider inventory must not produce a projection.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe($category);
    }
    Http::assertSentCount(2);
})->with([[true, 'provider_item_limit'], [false, 'provider_period_incomplete']]);
