<?php

use App\Models\User;
use Laravel\Cashier\Checkout;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config()->set('subscriptions.tiers.basic.stripe_price_id.monthly', 'price_test_basic_monthly');
    config()->set('subscriptions.tiers.basic.stripe_price_id.yearly', 'price_test_basic_yearly');
});

it('requires authentication', function () {
    $response = $this->postJson('/subscription/checkout', [
        'tier' => 'basic',
        'billing_period' => 'monthly',
    ]);

    $response->assertUnauthorized();
});

it('validates the tier', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/subscription/checkout', [
            'tier' => 'invalid',
            'billing_period' => 'monthly',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tier');
});

it('validates the billing period', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/subscription/checkout', [
            'tier' => 'basic',
            'billing_period' => 'invalid',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('billing_period');
});

it('prevents subscribing to free tier', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/subscription/checkout', [
            'tier' => 'free',
            'billing_period' => 'monthly',
        ])
        ->assertRedirect(route('subscription.plans'));
});

it('creates a checkout session for new subscription', function () {
    $user = User::factory()->create();

    $this->mock(Checkout::class, function ($mock) {
        $mock->shouldReceive('getAttribute')
            ->with('url')
            ->andReturn('https://checkout.stripe.com/test-session');
    });

    actingAs($user)
        ->postJson('/subscription/checkout', [
            'tier' => 'basic',
            'billing_period' => 'monthly',
        ])
        ->assertSuccessful()
        ->assertJson([
            'url' => 'https://checkout.stripe.com/test-session',
        ]);
});
