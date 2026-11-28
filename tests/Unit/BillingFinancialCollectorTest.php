<?php

use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingFinancialCollector;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingReturnUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['billing.durable.enabled' => true, 'billing.account.platform_id' => 'acct_financial',
        'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com',
        'app.url' => 'https://app.example.test', 'billing.portal.configuration_id' => 'bpc_restricted', 'cashier.secret' => 'synthetic_not_a_real_key']);
    Http::preventStrayRequests();
});

function financialCollectorInput(string $kind): array
{
    return ['operation' => 'prepare', 'kind' => $kind, 'operation_uuid' => '11111111-1111-4111-8111-111111111111',
        'account_id' => 'acct_financial', 'livemode' => false, 'customer_id' => 'cus_financial',
        'subscription_id' => 'sub_financial', 'price_id' => 'price_new'];
}

function financialProviderSubscription(): array
{
    return ['id' => 'sub_financial', 'object' => 'subscription', 'customer' => 'cus_financial', 'livemode' => false,
        'status' => 'active', 'cancel_at_period_end' => true, 'trial_end' => null,
        'items' => ['has_more' => false, 'data' => [['id' => 'si_financial', 'quantity' => 2,
            'price' => ['id' => 'price_old', 'recurring' => ['usage_type' => 'licensed']]]]]];
}

function fakeFinancialReads(array $extra = []): void
{
    Http::fake($extra + ['https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_financial', 'object' => 'account']),
        'https://api.stripe.com/v1/prices/price_new' => Http::response(['id' => 'price_new', 'object' => 'price', 'livemode' => false, 'active' => true, 'type' => 'recurring', 'recurring' => ['usage_type' => 'licensed']]),
        'https://api.stripe.com/v1/customers/cus_financial' => Http::response(['id' => 'cus_financial', 'object' => 'customer', 'livemode' => false]),
        'https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response(financialProviderSubscription())]);
}

it('characterizes installed single-price swap invoice and trial policies without sending a mutation during preparation', function () {
    fakeFinancialReads();
    $result = app(BillingFinancialCollector::class)->execute(financialCollectorInput('swap'));
    $form = $result['prepared']['form'];
    expect($form['payment_behavior'])->toBe('default_incomplete')->and($form['proration_behavior'])->toBe('always_invoice')
        ->and($form['trial_end'])->toBe('now')->and($form['cancel_at_period_end'])->toBe('false')
        ->and($form['items'])->toBe([['price' => 'price_new', 'quantity' => 2], ['id' => 'si_financial', 'deleted' => 'true']]);
    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('preserves an ongoing provider trial and refuses unsupported metered swaps', function () {
    $future = now('UTC')->addDay()->getTimestamp();
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response([...financialProviderSubscription(), 'trial_end' => $future])]);
    expect(app(BillingFinancialCollector::class)->execute(financialCollectorInput('resume'))['prepared']['form']['trial_end'])->toBe($future);
});

it('refuses metered swaps before any financial send', function () {
    $metered = financialProviderSubscription();
    $metered['items']['data'][0]['price']['recurring']['usage_type'] = 'metered';
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response($metered)]);
    expect(fn () => app(BillingFinancialCollector::class)->execute(financialCollectorInput('swap')))->toThrow(BillingProviderFailure::class);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('verifies an exact active mode-bound restricted portal configuration and server return URL', function (bool $updates, bool $mode) {
    fakeFinancialReads(['https://api.stripe.com/v1/billing_portal/configurations/bpc_restricted' => Http::response([
        'id' => 'bpc_restricted', 'object' => 'billing_portal.configuration', 'livemode' => $mode, 'active' => true,
        'features' => ['subscription_update' => ['enabled' => $updates]]])]);
    if ($updates || $mode) {
        expect(fn () => app(BillingFinancialCollector::class)->execute(financialCollectorInput('portal')))->toThrow(BillingProviderFailure::class);
    } else {
        $prepared = app(BillingFinancialCollector::class)->execute(financialCollectorInput('portal'))['prepared'];
        expect($prepared['form'])->toBe(['customer' => 'cus_financial', 'configuration' => 'bpc_restricted', 'return_url' => BillingReturnUrl::route('subscription.manage')]);
    }
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([[false, false], [true, false], [false, true]]);

it('performs one idempotency-bound mutation and rejects unbound returned operation metadata', function () {
    $prepared = ['kind' => 'cancel', 'path' => '/v1/subscriptions/sub_financial',
        'form' => ['metadata' => ['picksports_operation' => '11111111-1111-4111-8111-111111111111'], 'cancel_at_period_end' => 'true'], 'customer_id' => 'cus_financial', 'price_id' => null];
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => fn ($request) => Http::response([...financialProviderSubscription(), 'metadata' => ['picksports_operation' => $request->method() === 'POST' ? '22222222-2222-4222-8222-222222222222' : '11111111-1111-4111-8111-111111111111']])]);
    expect(fn () => app(BillingFinancialCollector::class)->execute(['operation' => 'send', 'account_id' => 'acct_financial', 'livemode' => false,
        'prepared' => $prepared, 'request_hash' => hash('sha256', json_encode($prepared, JSON_THROW_ON_ERROR)), 'step_key' => str_repeat('a', 64)]))->toThrow(BillingProviderFailure::class);
    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Idempotency-Key', str_repeat('a', 64)));
});

it('proves effective provider key mode from an exact price before admitting new customer creation', function () {
    fakeFinancialReads(['https://api.stripe.com/v1/prices/price_new' => Http::response([
        'id' => 'price_new', 'object' => 'price', 'livemode' => true, 'active' => true, 'type' => 'recurring', 'recurring' => ['usage_type' => 'licensed']])]);
    expect(fn () => app(BillingFinancialCollector::class)->execute([...financialCollectorInput('customer'), 'email' => 'synthetic@example.test']))
        ->toThrow(BillingProviderFailure::class);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('uses a configured HTTPS application return origin rather than an incoming or forced host', function () {
    URL::forceRootUrl('https://attacker.example');
    expect(BillingReturnUrl::route('subscription.manage'))->toBe('https://app.example.test/subscription/manage');
    config(['app.url' => 'http://app.example.test']);
    expect(fn () => BillingReturnUrl::route('subscription.manage'))->toThrow(BillingProviderFailure::class);
    Http::assertNothingSent();
});

it('keeps noncharge cancellation available under automatic tax while refusing an uncharacterized charge profile', function () {
    $original = Cashier::$calculatesTaxes;
    Cashier::$calculatesTaxes = true;
    try {
        fakeFinancialReads();
        expect(app(BillingFinancialCollector::class)->execute(financialCollectorInput('cancel'))['prepared']['kind'])->toBe('cancel');
        expect(fn () => app(BillingFinancialCollector::class)->execute(financialCollectorInput('swap')))->toThrow(BillingProviderFailure::class);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Cashier::$calculatesTaxes = $original;
    }
});

it('refuses resume outside the complete current provider grace window before any mutation', function (bool $partial) {
    $subscription = financialProviderSubscription();
    $subscription['items']['has_more'] = $partial;
    $subscription['items']['data'][0]['current_period_end'] = BillingClock::now()->getTimestamp() + ($partial ? 3600 : -1);
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response($subscription)]);
    expect(fn () => app(BillingFinancialCollector::class)->execute(financialCollectorInput('resume')))->toThrow(BillingProviderFailure::class);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([false, true]);

it('uses the complete maximum item end for resume grace rather than one expired item or a fabricated trial', function () {
    $subscription = financialProviderSubscription();
    $subscription['items']['data'][0]['current_period_end'] = BillingClock::now()->getTimestamp() - 1;
    $subscription['items']['data'][] = ['id' => 'si_future_grace', 'quantity' => 1,
        'current_period_end' => BillingClock::now()->addHour()->getTimestamp(),
        'price' => ['id' => 'price_other', 'recurring' => ['usage_type' => 'licensed']]];
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response($subscription)]);
    $form = app(BillingFinancialCollector::class)->execute(financialCollectorInput('resume'))['prepared']['form'];
    expect($form['cancel_at_period_end'])->toBe('false')->and($form['trial_end'])->toBe('now');
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('refuses a missing or malformed trial observation before a policy-writing resume or swap', function (string $kind, bool $missing) {
    $subscription = financialProviderSubscription();
    $subscription['items']['data'][0]['current_period_end'] = BillingClock::now()->addHour()->getTimestamp();
    if ($missing) {
        unset($subscription['trial_end']);
    } else {
        $subscription['trial_end'] = 'unknown';
    }
    fakeFinancialReads(['https://api.stripe.com/v1/subscriptions/sub_financial' => Http::response($subscription)]);
    expect(fn () => app(BillingFinancialCollector::class)->execute(financialCollectorInput($kind)))->toThrow(BillingProviderFailure::class);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([['resume', true], ['resume', false], ['swap', true], ['swap', false]]);
