<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['billing.durable.enabled' => false, 'billing.entitlements.enabled' => false,
        'subscriptions.default_tier' => 'free', 'founding_users.enabled' => false]);
    SubscriptionTier::factory()->create(['slug' => 'free', 'name' => 'Free', 'is_default' => true, 'is_active' => true,
        'permissions' => [], 'features' => [], 'data_permissions' => []]);
    Http::preventStrayRequests();
});

function checkoutReadAuthorityRows(): array
{
    $result = [];
    foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'billing_role_entitlements', 'billing_role_entitlement_events'] as $table) {
        $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }

    return $result;
}

it('does not synchronize role authority from an unqualified checkout return read', function (string $method) {
    $user = User::factory()->create();
    expect($user->subscriptions()->count())->toBe(0);
    $before = checkoutReadAuthorityRows();
    $this->actingAs($user)->call($method, '/subscription/success')->assertRedirect();
    expect(checkoutReadAuthorityRows())->toBe($before);
    Http::assertNothingSent();
})->with(['GET', 'HEAD']);

it('does not invoke the legacy role writer for an existing provenance-managed account', function (string $method) {
    config(['billing.entitlements.enabled' => true]);
    $user = User::factory()->create();
    DB::transaction(fn () => app(BillingRoleEntitlements::class)->grantNewAccountDefault($user, 'registration'));
    expect(app(BillingRoleEntitlements::class)->hasRecorded($user))->toBeTrue()
        ->and($user->subscriptions()->count())->toBe(0);
    $before = checkoutReadAuthorityRows();
    $this->actingAs($user)->call($method, '/subscription/success')->assertRedirect();
    expect(checkoutReadAuthorityRows())->toBe($before);
    Http::assertNothingSent();
})->with(['GET', 'HEAD']);

it('does not claim active paid access for an account with no subscription', function () {
    $user = User::factory()->create();
    expect($user->subscriptions()->count())->toBe(0);
    $response = $this->actingAs($user)->get('/subscription/success');
    $response->assertRedirect()->assertSessionHas('success', fn ($message) => ! str_contains(strtolower($message), 'subscription is now active'));
    Http::assertNothingSent();
});
