<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config()->set('subscriptions.tiers.basic.stripe_price_id.monthly', 'price_test_basic_monthly');
    config()->set('subscriptions.tiers.pro.stripe_price_id.monthly', 'price_test_pro_monthly');

    if (! Route::has('tests.subscribed.web.base')) {
        Route::middleware(['web', 'auth', 'subscribed'])
            ->get('/__tests__/subscribed/web/base', fn () => response()->json(['ok' => true]))
            ->name('tests.subscribed.web.base');
    }

    if (! Route::has('tests.subscribed.web.pro')) {
        Route::middleware(['web', 'auth', 'subscribed:pro'])
            ->get('/__tests__/subscribed/web/pro', fn () => response()->json(['ok' => true]))
            ->name('tests.subscribed.web.pro');
    }

    if (! Route::has('tests.subscribed.api.base')) {
        Route::middleware(['api', 'auth:sanctum', 'subscribed'])
            ->get('/__tests__/subscribed/api/base', fn () => response()->json(['ok' => true]))
            ->name('tests.subscribed.api.base');
    }

    if (! Route::has('tests.subscribed.api.pro')) {
        Route::middleware(['api', 'auth:sanctum', 'subscribed:pro'])
            ->get('/__tests__/subscribed/api/pro', fn () => response()->json(['ok' => true]))
            ->name('tests.subscribed.api.pro');
    }
});

function createActiveSubscription(User $user, string $price): void
{
    DB::table('subscriptions')->insert([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(16),
        'stripe_status' => 'active',
        'stripe_price' => $price,
        'quantity' => 1,
        'trial_ends_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('requires auth for subscribed web route', function () {
    $this->get('/__tests__/subscribed/web/base')
        ->assertRedirect(route('login'));
});

it('denies unsubscribed web users with json response', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/__tests__/subscribed/web/base')
        ->assertForbidden()
        ->assertJsonPath('message', 'An active subscription is required to access this resource.');
});

it('allows subscribed web users', function () {
    $user = User::factory()->create();
    createActiveSubscription($user, 'price_test_basic_monthly');

    $this->actingAs($user)
        ->getJson('/__tests__/subscribed/web/base')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('denies unsubscribed api users', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/__tests__/subscribed/api/base')
        ->assertForbidden()
        ->assertJsonPath('message', 'An active subscription is required to access this resource.');
});

it('allows subscribed api users', function () {
    $user = User::factory()->create();
    createActiveSubscription($user, 'price_test_basic_monthly');
    Sanctum::actingAs($user);

    $this->getJson('/__tests__/subscribed/api/base')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('denies insufficient tier on web subscribed route', function () {
    $user = User::factory()->create();
    createActiveSubscription($user, 'price_test_basic_monthly');

    $this->actingAs($user)
        ->getJson('/__tests__/subscribed/web/pro')
        ->assertForbidden()
        ->assertJsonPath('message', 'This feature requires a pro subscription or higher.');
});

it('denies insufficient tier on api subscribed route', function () {
    $user = User::factory()->create();
    createActiveSubscription($user, 'price_test_basic_monthly');
    Sanctum::actingAs($user);

    $this->getJson('/__tests__/subscribed/api/pro')
        ->assertForbidden()
        ->assertJsonPath('message', 'This feature requires a pro subscription or higher.');
});

it('allows required tier on subscribed pro routes', function () {
    $user = User::factory()->create();
    createActiveSubscription($user, 'price_test_pro_monthly');

    $this->actingAs($user)
        ->getJson('/__tests__/subscribed/web/pro')
        ->assertOk()
        ->assertJson(['ok' => true]);

    Sanctum::actingAs($user);

    $this->getJson('/__tests__/subscribed/api/pro')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
