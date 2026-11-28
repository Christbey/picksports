<?php

use App\Models\BillingReconciliationAttempt;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingSubscriptionPresentation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
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
    Http::preventStrayRequests();
});

it('never presents a legacy cancellation date as an observed renewal date', function () {
    $user = User::factory()->create();
    $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_presentation',
        'stripe_status' => 'active', 'stripe_price' => 'price_presentation', 'quantity' => 1, 'ends_at' => now()->addDay()]);
    $view = app(BillingSubscriptionPresentation::class)->present($subscription, 'Effective manual tier');
    expect($view['current_period_end'])->toBeNull()->and($view['period_end_source'])->toBe('unavailable')
        ->and($view['scheduled_access_end'])->toBe($subscription->ends_at->toIso8601String())
        ->and($view['tier'])->toBe('Effective manual tier');
    Http::assertNothingSent();
});

it('refuses administrative refresh before any legacy SDK lookup when durable context is disabled', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $role = Role::findOrCreate('admin', 'web');
    $admin->assignRole($role);
    $user = User::factory()->create(['stripe_id' => 'cus_admin_refusal']);
    $this->actingAs($admin)->post('/admin/subscriptions/'.$user->id.'/sync')->assertRedirect()
        ->assertSessionHas('error');
    $this->post('/admin/subscriptions/sync-all', ['limit' => 101])->assertSessionHasErrors('limit');
    Http::assertNothingSent();
    expect(BillingReconciliationAttempt::query()->count())->toBe(0);
});

it('classifies canonical trial equality as expired without expiring a continuing active subscription', function () {
    $epoch = BillingClock::now()->getTimestamp();
    $subscription = new BillingSubscription;
    $subscription->forceFill(['billing_projection_attempt_id' => 1, 'billing_trial_end_epoch' => $epoch,
        'billing_end_epoch' => null, 'stripe_status' => 'active']);
    expect($subscription->onTrial())->toBeFalse()->and($subscription->hasExpiredTrial())->toBeTrue()
        ->and($subscription->active())->toBeTrue();
});
