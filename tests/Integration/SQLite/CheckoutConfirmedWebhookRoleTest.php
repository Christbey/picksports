<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('grants paid legacy access from the signed confirmed webhook without visiting the checkout return', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['billing.durable.enabled' => false, 'billing.entitlements.enabled' => false,
        'founding_users.enabled' => false, 'cashier.webhook.secret' => 'whsec_synthetic_return_boundary']);
    Http::preventStrayRequests();
    SubscriptionTier::factory()->create(['name' => 'Free', 'slug' => 'free', 'is_default' => true, 'permissions' => [], 'features' => []]);
    SubscriptionTier::factory()->create(['name' => 'Return boundary paid', 'slug' => 'return_boundary_paid', 'is_default' => false,
        'stripe_price_id_monthly' => 'price_return_boundary', 'permissions' => [], 'features' => []]);
    $user = User::factory()->create(['stripe_id' => 'cus_return_boundary']);
    expect($user->hasRole('return_boundary_paid'))->toBeFalse();
    $payload = json_encode(['id' => 'evt_return_boundary', 'created' => time(),
        'type' => 'customer.subscription.created', 'data' => ['object' => [
            'id' => 'sub_return_boundary', 'customer' => 'cus_return_boundary', 'status' => 'active',
            'cancel_at_period_end' => false, 'items' => ['data' => [[
                'id' => 'si_return_boundary', 'price' => ['id' => 'price_return_boundary', 'product' => 'prod_return_boundary'],
                'quantity' => 1,
            ]]],
        ]]], JSON_THROW_ON_ERROR);
    $time = time();
    $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_synthetic_return_boundary');
    $this->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
    ], $payload)->assertOk();
    expect($user->fresh()->subscribed())->toBeTrue()
        ->and($user->fresh()->hasRole('return_boundary_paid'))->toBeTrue();
    Http::assertNothingSent();
});

afterEach(function () {
    // Standalone migrations must not leak into a later RefreshDatabase suite.
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});
