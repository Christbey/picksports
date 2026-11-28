<?php

use App\Http\Controllers\WebhookController;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\BillingWebhookReceipt;
use App\Models\User;
use App\Services\Billing\BillingCheckoutReservations;
use App\Services\SubscriptionCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\Checkout\Session;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => false, 'founding_users.enabled' => false]);
});

it('retains billing ownership and authentication when provider termination is unproved', function (string $status) {
    $user = User::factory()->create(['stripe_id' => 'cus_delete_synthetic']);
    $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_delete_synthetic',
        'stripe_status' => $status, 'stripe_price' => 'price_delete_synthetic', 'quantity' => 1,
        'ends_at' => $status === 'canceled' ? now()->subDay() : null]);
    $this->actingAs($user)->deleteJson('/settings/profile', ['password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->assertAuthenticated();
    expect($user->fresh())->not->toBeNull()->and($subscription->fresh())->not->toBeNull();
})->with(['active', 'canceled']);

it('still deletes an authenticated free account with no billing identity or pending operation', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');
    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

it('admits a durable user checkout hold before hidden SDK customer creation can race account deletion', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $subject = Mockery::mock(User::class)->makePartial();
    $subject->setRawAttributes($user->getAttributes(), true);
    $subject->exists = true;
    $builder = Mockery::mock(SubscriptionBuilder::class);
    $subject->shouldReceive('newSubscription')->once()->with('default', 'price_checkout_synthetic')->andReturn($builder);
    $builder->shouldReceive('checkout')->once()->andReturnUsing(function () use ($user): Checkout {
        // This is the real service's provider boundary, before a customer ID can be saved locally.
        expect($user->fresh()->stripe_id)->toBeNull();
        $this->deleteJson('/settings/profile', ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('billing');
        $this->assertAuthenticated();
        expect($user->fresh())->not->toBeNull();
        DB::table('users')->where('id', $user->id)->update(['stripe_id' => 'cus_checkout_synthetic']);

        return new Checkout($user->fresh(), Session::constructFrom(['id' => 'cs_checkout_synthetic', 'object' => 'checkout.session',
            'customer' => 'cus_checkout_synthetic', 'mode' => 'subscription', 'livemode' => false, 'url' => 'https://checkout.stripe.com/synthetic']));
    });
    expect(app(SubscriptionCheckoutService::class)->createCheckoutUrl($subject, 'price_checkout_synthetic'))
        ->toBe('https://checkout.stripe.com/synthetic');
});

it('retains an uncertain pre-customer checkout hold after SDK failure and refuses a second SDK attempt', function () {
    $user = User::factory()->create();
    $subject = Mockery::mock(User::class)->makePartial();
    $subject->setRawAttributes($user->getAttributes(), true);
    $subject->exists = true;
    $builder = Mockery::mock(SubscriptionBuilder::class);
    $subject->shouldReceive('newSubscription')->once()->andReturn($builder);
    $builder->shouldReceive('checkout')->once()->andReturnUsing(function () {
        expect(DB::transactionLevel())->toBe(0);
        throw new RuntimeException('Synthetic lost acknowledgement');
    });
    expect(fn () => app(SubscriptionCheckoutService::class)->createCheckoutUrl($subject, 'price_checkout_unknown'))
        ->toThrow(RuntimeException::class, 'Synthetic lost acknowledgement');
    expect(BillingMutationState::query()->sole()->status)->toBe('uncertain');
    expect(fn () => app(SubscriptionCheckoutService::class)->createCheckoutUrl($subject, 'price_checkout_unknown'))
        ->toThrow(ValidationException::class);
    $this->actingAs($user)->deleteJson('/settings/profile', ['password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->assertAuthenticated();
    $receipt = BillingMutationReceipt::query()->orderBy('id')->firstOrFail();
    expect(fn () => $receipt->incrementQuietly('lease_sequence'))->toThrow(LogicException::class);
    expect(fn () => $receipt->deleteQuietly())->toThrow(LogicException::class);
    expect($receipt->fresh()->phase)->toBe('checkout_possible_send');
});

it('retains the deletion hold after reservation lease expiry rather than assuming no provider side effect', function () {
    $user = User::factory()->create();
    app(BillingCheckoutReservations::class)->reserve($user, 'price_checkout_expired', []);
    $this->travel(3)->minutes();
    $this->actingAs($user)->deleteJson('/settings/profile', ['password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('billing');
    expect($user->fresh())->not->toBeNull();
});

it('releases only an acknowledged legacy checkout linked by exact generated subscription metadata without calling it a paid confirmation', function (bool $matchingOperation) {
    $user = User::factory()->create(['stripe_id' => 'cus_legacy_link_synthetic']);
    $reservations = app(BillingCheckoutReservations::class);
    $lease = $reservations->reserve($user, 'price_legacy_link', []);
    $reservations->acknowledged($lease, $user->stripe_id, 'cs_legacy_link_synthetic');
    $intent = BillingMutationIntent::query()->findOrFail($lease->intentId);
    $event = ['id' => 'evt_legacy_link_synthetic', 'type' => 'customer.subscription.created', 'data' => ['object' => [
        'id' => 'sub_legacy_link_synthetic', 'customer' => $user->stripe_id, 'status' => 'active', 'cancel_at_period_end' => false,
        'metadata' => ['type' => 'default', 'picksports_operation' => $matchingOperation ? $intent->operation_uuid : '11111111-1111-4111-8111-111111111111'],
        'items' => ['data' => [['id' => 'si_legacy_link_synthetic', 'quantity' => 1, 'price' => ['id' => 'price_legacy_link', 'product' => 'prod_legacy_link']]]]]]];
    app(WebhookController::class)->handleCustomerSubscriptionCreated($event);
    $state = BillingMutationState::query()->findOrFail($lease->stateId);
    expect($state->active_intent_id)->toBe($matchingOperation ? null : $intent->id)
        ->and(BillingMutationReceipt::query()->where('phase', 'legacy_subscription_linked')->count())->toBe($matchingOperation ? 1 : 0)
        ->and(BillingMutationReceipt::query()->where('phase', 'projection_confirmed')->count())->toBe(0)
        ->and(BillingWebhookReceipt::query()->count())->toBe(0);
})->with([true, false]);
