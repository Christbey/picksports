<?php

use App\Http\Controllers\WebhookController;
use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingAuthoritativeGateway;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingFinancialAdmission;
use App\Services\Billing\BillingFinancialExecutor;
use App\Services\Billing\BillingFinancialGateway;
use App\Services\Billing\BillingFinancialScanner;
use App\Services\Billing\BillingLegacyOperation;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingReconciler;
use App\Services\SubscriptionPeriodEndResolver;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    $this->travelBack();
    if (isset($this->originalStripeHttpClient)) {
        ApiRequestor::setHttpClient($this->originalStripeHttpClient);
    }
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true, 'billing.account.platform_id' => 'acct_financial_synthetic',
        'app.url' => 'https://app.example.test', 'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com',
        'founding_users.enabled' => false]);
    Http::preventStrayRequests();
    config(['cashier.secret' => 'synthetic_not_a_real_key']);
    $this->originalStripeHttpClient = ApiRequestor::httpClient();
    $stripeHttp = Mockery::mock(ClientInterface::class);
    $stripeHttp->shouldReceive('request')->andThrow(new LogicException('Real SDK requests are forbidden in these tests.'));
    ApiRequestor::setHttpClient($stripeHttp);
});

function financialPrepared(string $kind): array
{
    $prepared = ['kind' => $kind, 'path' => '/v1/customers', 'form' => ['metadata' => ['picksports_operation' => 'synthetic']], 'customer_id' => null];

    return ['prepared' => $prepared, 'request_hash' => hash('sha256', json_encode($prepared, JSON_THROW_ON_ERROR))];
}

it('reuses a server-equivalent charge intent and refuses a contradictory intent without provider work', function () {
    $user = User::factory()->create();
    $first = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $second = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    expect($second->id)->toBe($first->id)->and(BillingMutationIntent::query()->count())->toBe(1);
    expect(fn () => app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_two'))->toThrow(ValidationException::class);
    expect(BillingMutationReceipt::query()->sole()->phase)->toBe('requested');
    Http::assertNothingSent();
});

it('does not admit a send after preparation failure and keeps prior user identity', function () {
    $user = User::factory()->create();
    app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($request) => $request['operation'] === 'prepare')
        ->andThrow(new BillingProviderFailure('provider_scope_mismatch'));
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeFalse()
        ->and($state->fresh()->status)->toBe('attention')->and($user->fresh()->stripe_id)->toBeNull()
        ->and(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe(0);
});

it('retains possible-send ambiguity and never blindly sends the same customer operation again', function () {
    $user = User::factory()->create();
    app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('customer'));
    $gateway->shouldReceive('execute')->once()->withArgs(function ($r) {
        expect(DB::transactionLevel())->toBe(0)
            ->and(BillingMutationReceipt::query()->where('phase', 'possible_send_customer')->count())->toBe(1);

        return $r['operation'] === 'send';
    })->andThrow(new BillingProviderFailure('provider_timeout'));
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeFalse()
        ->and($state->fresh()->status)->toBe('uncertain')->and($state->fresh()->active_intent_id)->not->toBeNull();
    $this->travel(10)->minutes();
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeFalse()
        ->and(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe(1);
});

it('acknowledges actual customer identity before a separately admitted checkout step and exposes only an owned encrypted result', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare' && $r['kind'] === 'customer')->andReturn(financialPrepared('customer'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send' && $r['prepared']['kind'] === 'customer')
        ->andReturn(['id' => 'cus_created_synthetic', 'customer_id' => 'cus_created_synthetic']);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare' && $r['kind'] === 'checkout' && $r['customer_id'] === 'cus_created_synthetic')->andReturn(financialPrepared('checkout'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send' && $r['prepared']['kind'] === 'checkout')
        ->andReturn(['id' => 'cs_created_synthetic', 'customer_id' => 'cus_created_synthetic', 'url' => 'https://checkout.stripe.com/synthetic']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()->and($state->fresh()->status)->toBe('pending_next')
        ->and($user->fresh()->stripe_id)->toBe('cus_created_synthetic');
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()->and($state->fresh()->status)->toBe('session_open')
        ->and(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe(2);
    expect(DB::table('billing_mutation_states')->where('id', $state->id)->value('result'))->not->toContain('checkout.stripe.com');
    $this->actingAs($other)->getJson('/subscription/operations/'.$intent->id)->assertNotFound();
    $this->actingAs($user)->getJson('/subscription/operations/'.$intent->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('url', 'https://checkout.stripe.com/synthetic');
    config(['billing.account.platform_id' => 'acct_other_synthetic']);
    $this->getJson('/subscription/operations/'.$intent->id)->assertOk()->assertJsonPath('url', null)
        ->assertJsonPath('result_unavailable', true)->assertJsonPath('result_expired', false);
    config(['billing.account.platform_id' => 'acct_financial_synthetic']);
    $this->travel(16)->minutes();
    $this->getJson('/subscription/operations/'.$intent->id)->assertOk()->assertJsonPath('url', null)->assertJsonPath('result_expired', true);
    expect($state->fresh()->active_intent_id)->toBe($intent->id);
});

it('refuses unconfigured portal management without a provider or intent', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_portal_synthetic']);
    config(['billing.portal.configuration_id' => null]);
    $this->actingAs($user)->postJson('/subscription/billing-portal')->assertUnprocessable()->assertJsonValidationErrors('billing');
    expect(BillingMutationIntent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires authentication and immutable ownership evidence before exposing a legacy opaque payment link', function () {
    $url = route('cashier.payment', ['id' => 'pi_unowned_synthetic']);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    Http::assertNothingSent();
});

it('observes completed owned checkout and durably targets the actual new subscription even without a create webhook', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_observed_synthetic']);
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('checkout'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => 'cs_observed_synthetic', 'customer_id' => 'cus_observed_synthetic', 'url' => 'https://checkout.stripe.com/observed']);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'checkout_read' && $r['operation_uuid'] === $intent->operation_uuid)
        ->andReturn(['id' => 'cs_observed_synthetic', 'customer_id' => 'cus_observed_synthetic', 'status' => 'complete',
            'payment_status' => 'paid', 'subscription_id' => 'sub_observed_synthetic', 'url' => null]);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue();
    $oldOwner = (string) Str::uuid();
    $oldState = BillingCustomerReconciliation::query()->create(['account_id' => 'acct_financial_synthetic', 'livemode' => false,
        'customer_id' => $user->stripe_id, 'user_id' => $user->id, 'status' => 'processing', 'generation' => 1, 'lease_sequence' => 1,
        'owner_token' => $oldOwner, 'lease_expires_at' => BillingClock::now()->subSecond()]);
    $oldAttempt = BillingReconciliationAttempt::query()->create(['reconciliation_id' => $oldState->id, 'generation' => 1, 'lease_sequence' => 1,
        'owner_token' => $oldOwner, 'kind' => 'observation', 'status' => 'running', 'targets' => [], 'started_at' => BillingClock::now()->subMinutes(3)]);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()
        ->and($state->fresh()->status)->toBe('awaiting_reconciliation')
        ->and($user->subscriptions()->count())->toBe(0)
        ->and(BillingWebhookReceipt::query()->count())->toBe(0);
    $request = BillingReconciliationAttempt::query()->where('kind', 'operator_request')->sole();
    expect($oldAttempt->fresh()->status)->toBe('stale');
    expect($request->kind)->toBe('operator_request')->and($request->targets)->toBe(['sub_observed_synthetic'])
        ->and($request->details['actor'])->toBe('financial_checkout');
    expect(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe(1);
});

it('releases a known session hold only after current scoped expiry proof and never removes the actual customer linkage', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_expired_synthetic']);
    app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('checkout'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => 'cs_expired_synthetic', 'customer_id' => 'cus_expired_synthetic', 'url' => 'https://checkout.stripe.com/expired']);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'checkout_read')->andReturn([
        'id' => 'cs_expired_synthetic', 'customer_id' => 'cus_expired_synthetic', 'status' => 'expired',
        'payment_status' => 'unpaid', 'subscription_id' => null, 'url' => null]);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue();
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()
        ->and($state->fresh()->active_intent_id)->toBeNull()->and($state->fresh()->status)->toBe('expired')
        ->and($user->fresh()->stripe_id)->toBe('cus_expired_synthetic');
    $this->actingAs($user)->deleteJson('/settings/profile', ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('billing');
});

it('starts exactly one delayed queued execution with a fresh bounded lease and refuses a duplicate job', function () {
    $user = User::factory()->create();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.queue' => 'default',
        'queue.connections.database.retry_after' => 150, 'queue.worker_contracts.default.timeout' => 120]);
    Bus::fake();
    $state = BillingMutationState::query()->sole();
    expect(app(BillingFinancialScanner::class)->scan(10, true)['enqueued'])->toBe(1);
    $enqueued = $state->fresh();
    $token = $enqueued->owner_token;
    $this->travel(119)->seconds();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(function ($r) use ($state) {
        expect($state->fresh()->lease_expires_at->getTimestamp() - BillingClock::now()->getTimestamp())->toBe(90);

        return $r['operation'] === 'prepare';
    })->andReturn(financialPrepared('customer'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')
        ->andReturn(['id' => 'cus_delayed_synthetic', 'customer_id' => 'cus_delayed_synthetic']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id, $intent->id, $token))->toBeTrue()
        ->and(app(BillingFinancialExecutor::class)->run($state->id, $intent->id, $token))->toBeFalse()
        ->and(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe(1);
});

it('refuses a financial handoff through the synchronous queue without claiming or contacting the provider', function () {
    $user = User::factory()->create();
    app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    config(['queue.default' => 'sync']);
    Bus::fake();
    expect(app(BillingFinancialScanner::class)->scan(10, true)['enqueued'])->toBe(0)
        ->and(BillingMutationState::query()->sole()->status)->toBe('pending');
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('refuses a late preparation before the send receipt and retains a late possible-send acknowledgement as uncertain', function (bool $lateAfterSend) {
    $user = User::factory()->create();
    app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturnUsing(function () use ($lateAfterSend) {
        if (! $lateAfterSend) {
            $this->travel(91)->seconds();
        }

        return financialPrepared('customer');
    });
    if ($lateAfterSend) {
        $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturnUsing(function () {
            $this->travel(91)->seconds();

            return ['id' => 'cus_late_synthetic', 'customer_id' => 'cus_late_synthetic'];
        });
    }
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeFalse()
        ->and($state->fresh()->status)->toBe($lateAfterSend ? 'uncertain' : 'attention')
        ->and($user->fresh()->stripe_id)->toBeNull()
        ->and(BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count())->toBe($lateAfterSend ? 1 : 0);
})->with([false, true]);

function financialCanonicalFixture(): array
{
    config(['billing.durable.enabled' => false]);
    $user = User::factory()->create(['stripe_id' => 'cus_managed_synthetic']);
    $legacy = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_legacy_synthetic', 'stripe_status' => 'active', 'stripe_price' => 'price_one', 'quantity' => 1]);
    $managed = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_managed_synthetic', 'stripe_status' => 'active', 'stripe_price' => 'price_one', 'quantity' => 1]);
    $state = BillingCustomerReconciliation::query()->create(['account_id' => 'acct_financial_synthetic', 'livemode' => false, 'customer_id' => $user->stripe_id, 'user_id' => $user->id]);
    $attempt = BillingReconciliationAttempt::query()->create(['reconciliation_id' => $state->id, 'generation' => 0, 'lease_sequence' => 0,
        'owner_token' => (string) Str::uuid(), 'kind' => 'observation', 'status' => 'succeeded', 'targets' => [],
        'started_at' => BillingClock::now(), 'completed_at' => BillingClock::now()]);
    // A persisted preexisting canonical fixture, not an application write-capability bypass.
    DB::table('subscriptions')->where('id', $managed->id)->update(['billing_projection_attempt_id' => $attempt->id, 'billing_created_epoch' => 1700000000]);

    return [$user, $legacy, $managed, $state];
}

it('refuses every default-off managed financial helper before the provider callback', function () {
    [$user, $legacy, $managed] = financialCanonicalFixture();
    foreach (['cancel', 'resume', 'swap'] as $kind) {
        expect(fn () => app(BillingLegacyOperation::class)->run($user, $kind, fn () => test()->fail('Managed provider callback ran')))
            ->toThrow(ValidationException::class);
    }
    $this->actingAs($user)->postJson('/subscription/cancel')->assertUnprocessable()->assertJsonValidationErrors('billing');
    expect($legacy->fresh()->stripe_status)->toBe('active')->and($managed->fresh()->stripe_status)->toBe('active')
        ->and(BillingMutationIntent::query()->count())->toBe(0);
});

it('refuses default-off managed customer deletion atomically and subscription events before any period-provider lookup', function () {
    [$user, $legacy, $managed] = financialCanonicalFixture();
    $resolver = Mockery::mock(SubscriptionPeriodEndResolver::class);
    $resolver->shouldNotReceive('resolve');
    $controller = new WebhookController($resolver);
    expect(fn () => $controller->handleCustomerDeleted(['data' => ['object' => ['id' => $user->stripe_id]]]))->toThrow(LogicException::class);
    expect(fn () => $controller->handleCustomerSubscriptionUpdated(['data' => ['object' => ['id' => $managed->stripe_id,
        'customer' => $user->stripe_id, 'cancel_at_period_end' => true, 'status' => 'active']]]))->toThrow(LogicException::class);
    expect($user->fresh()->stripe_id)->toBe('cus_managed_synthetic')
        ->and($legacy->fresh()->stripe_status)->toBe('active')->and($managed->fresh()->stripe_status)->toBe('active');
});

function financialManagedUser(): array
{
    [$user, $legacy, $managed, $state] = financialCanonicalFixture();
    $legacy->delete();
    $user->unsetRelation('subscriptions');
    config(['billing.durable.enabled' => true]);
    SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true, 'permissions' => [], 'features' => []]);
    SubscriptionTier::factory()->create(['slug' => 'financial_old', 'name' => 'Old', 'stripe_price_id_monthly' => 'price_one', 'permissions' => [], 'features' => []]);
    SubscriptionTier::factory()->create(['slug' => 'financial_new', 'name' => 'New', 'stripe_price_id_monthly' => 'price_new', 'permissions' => [], 'features' => []]);

    return [$user, $managed, $state];
}

function financialCurrentObservation(User $user, string $operation, bool $cancel = false, string $price = 'price_one', ?string $invoiceStatus = null): array
{
    return ['account_id' => 'acct_financial_synthetic', 'livemode' => false, 'customer_id' => $user->stripe_id, 'customer_deleted' => false,
        'fetch_started_at' => BillingClock::now()->getTimestamp(), 'fetch_completed_at' => BillingClock::now()->getTimestamp(),
        'subscriptions' => [['id' => 'sub_managed_synthetic', 'customer_id' => $user->stripe_id, 'livemode' => false,
            'type' => 'default', 'status' => 'active', 'created' => 1700000000, 'operation_uuid' => $operation,
            'invoice_id' => $invoiceStatus !== null ? 'in_financial_synthetic' : null, 'invoice_status' => $invoiceStatus,
            'trial_end' => null, 'cancel_at' => null, 'canceled_at' => null, 'ended_at' => null, 'cancel_at_period_end' => $cancel,
            'items' => [['id' => 'si_financial_synthetic', 'price' => $price, 'product' => 'prod_financial_synthetic', 'quantity' => 1,
                'current_period_end' => BillingClock::now()->addMonth()->getTimestamp()]]]]];
}

it('commits an acknowledged cancellation outcome with current canonical projection and its immutable receipt', function () {
    [$user, $subscription, $reconciliation] = financialManagedUser();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'cancel');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('cancel'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => $subscription->stripe_id, 'customer_id' => $user->stripe_id, 'status' => 'active']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()->and($state->fresh()->status)->toBe('awaiting_reconciliation');
    $current = Mockery::mock(BillingAuthoritativeGateway::class);
    $current->shouldReceive('fetch')->once()->andReturn(financialCurrentObservation($user, $intent->operation_uuid, true));
    app()->instance(BillingAuthoritativeGateway::class, $current);
    expect(app(BillingReconciler::class)->run($reconciliation->id))->toBeTrue()
        ->and($state->fresh()->status)->toBe('completed')->and($state->fresh()->active_intent_id)->toBeNull()
        ->and($subscription->fresh()->billing_cancel_at_period_end)->toBeTrue()
        ->and(BillingMutationReceipt::query()->where('phase', 'projection_confirmed')->count())->toBe(1);
});

it('preserves prior entitlements for an unconfirmed swap and exposes only the bound hosted-invoice continuation', function () {
    [$user, $subscription, $reconciliation] = financialManagedUser();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'swap', 'price_new');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('swap'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => $subscription->stripe_id, 'customer_id' => $user->stripe_id, 'status' => 'active',
        'invoice_id' => 'in_financial_synthetic', 'invoice_status' => 'open', 'invoice_url' => 'https://invoice.stripe.com/owned-synthetic']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue()->and($state->fresh()->status)->toBe('payment_pending');
    $this->actingAs($user)->getJson('/subscription/operations/'.$intent->id)->assertOk()->assertJsonPath('url', 'https://invoice.stripe.com/owned-synthetic');
    $current = Mockery::mock(BillingAuthoritativeGateway::class);
    $current->shouldReceive('fetch')->once()->andReturn(financialCurrentObservation($user, $intent->operation_uuid, false, 'price_new', 'open'));
    app()->instance(BillingAuthoritativeGateway::class, $current);
    expect(app(BillingReconciler::class)->run($reconciliation->id))->toBeFalse()
        ->and($subscription->fresh()->stripe_price)->toBe('price_one')->and($state->fresh()->status)->toBe('payment_pending')
        ->and(BillingMutationReceipt::query()->where('phase', 'projection_confirmed')->count())->toBe(0);
    expect(BillingReconciliationAttempt::query()->where('failure_category', 'financial_payment_unconfirmed')->count())->toBe(1);
});

it('confirms a swap only with matching paid invoice operation and current subscription proof', function () {
    [$user, $subscription, $reconciliation] = financialManagedUser();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'swap', 'price_new');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('swap'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => $subscription->stripe_id, 'customer_id' => $user->stripe_id, 'status' => 'active',
        'invoice_id' => 'in_financial_synthetic', 'invoice_status' => 'paid']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue();
    $current = Mockery::mock(BillingAuthoritativeGateway::class);
    $current->shouldReceive('fetch')->once()->andReturn(financialCurrentObservation($user, $intent->operation_uuid, false, 'price_new', 'paid'));
    app()->instance(BillingAuthoritativeGateway::class, $current);
    expect(app(BillingReconciler::class)->run($reconciliation->id))->toBeTrue()
        ->and($subscription->fresh()->stripe_price)->toBe('price_new')->and($state->fresh()->status)->toBe('completed')
        ->and($state->fresh()->active_intent_id)->toBeNull()
        ->and(BillingMutationReceipt::query()->where('phase', 'projection_confirmed')->sole()->facts['invoice_id'])->toBe('in_financial_synthetic');
});

it('rolls back current subscription and financial completion together if the immutable outcome insert fails', function () {
    [$user, $subscription, $reconciliation] = financialManagedUser();
    $intent = app(BillingFinancialAdmission::class)->admit($user, 'cancel');
    $state = BillingMutationState::query()->sole();
    $gateway = Mockery::mock(BillingFinancialGateway::class);
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'prepare')->andReturn(financialPrepared('cancel'));
    $gateway->shouldReceive('execute')->once()->withArgs(fn ($r) => $r['operation'] === 'send')->andReturn([
        'id' => $subscription->stripe_id, 'customer_id' => $user->stripe_id, 'status' => 'active']);
    app()->instance(BillingFinancialGateway::class, $gateway);
    expect(app(BillingFinancialExecutor::class)->run($state->id))->toBeTrue();
    $current = Mockery::mock(BillingAuthoritativeGateway::class);
    $current->shouldReceive('fetch')->once()->andReturn(financialCurrentObservation($user, $intent->operation_uuid, true));
    app()->instance(BillingAuthoritativeGateway::class, $current);
    $armed = true;
    DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$armed) {
        if ($armed && str_contains(strtolower($sql), 'insert into') && str_contains($sql, 'billing_mutation_receipts') && in_array('projection_confirmed', $bindings, true)) {
            $armed = false;
            throw new RuntimeException('Synthetic immutable outcome storage failure');
        }
    });
    expect(app(BillingReconciler::class)->run($reconciliation->id))->toBeFalse()->and($armed)->toBeFalse()
        ->and($subscription->fresh()->billing_cancel_at_period_end)->toBeNull()
        ->and($state->fresh()->status)->toBe('awaiting_reconciliation')->and($state->fresh()->active_intent_id)->toBe($intent->id)
        ->and(BillingMutationReceipt::query()->where('phase', 'projection_confirmed')->count())->toBe(0);
});

it('rejects malformed operation route identities as missing resources without a provider call', function (string $id) {
    $this->actingAs(User::factory()->create())->getJson('/subscription/operations/'.$id)->assertNotFound();
    Http::assertNothingSent();
})->with(['not-an-id', '0', '999999999999999999999999999999']);

it('refuses new charges before durable intent or provider work when entitlement provenance admission is disabled', function () {
    config(['billing.entitlements.enabled' => false]);
    $user = User::factory()->create();
    expect(fn () => app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_one'))->toThrow(ValidationException::class);
    expect(BillingMutationIntent::query()->count())->toBe(0)->and(BillingMutationState::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps stored past-due billing visible and allows cancellation admission independently of access validity', function () {
    [$user, $subscription] = financialManagedUser();
    DB::table('subscriptions')->where('id', $subscription->id)->update(['stripe_status' => 'past_due']);
    config(['inertia.ssr.enabled' => false, 'billing.entitlements.enabled' => false]);
    $this->actingAs($user)->get('/subscription/manage')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Subscription/Manage')->where('subscription.status', 'past_due')->where('subscription.can_cancel', true));
    $this->post('/subscription/cancel')->assertRedirect();
    expect(BillingMutationIntent::query()->sole()->kind)->toBe('cancel');
    Http::assertNothingSent();
});
