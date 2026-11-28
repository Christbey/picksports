<?php

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use App\Services\Billing\BillingAuthoritativeGateway;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\BillingRoleEntitlements;
use App\Services\Billing\BillingWebhookAdmission;

function canonicalEntitlementUser(?string $existingOrigin = null, bool $expectedResult = true): array
{
    $user = User::factory()->create(['stripe_id' => 'cus_entitlement_synthetic', 'age_verified_at' => now(), 'email_verified_at' => now()]);
    if ($existingOrigin !== null) {
        $tier = SubscriptionTier::query()->where('slug', 'entitlement_synthetic')->sole();
        if ($existingOrigin === 'manual') {
            app(BillingRoleEntitlements::class)->grantManualTier($user, $tier);
        } else {
            $user->assignRole(app(TierPermissionSyncService::class)->syncTierRolePermissions($tier));
        }
    }
    $end = now()->getTimestamp() + 60;
    $event = ['id' => 'evt_entitlement_synthetic', 'type' => 'customer.subscription.updated', 'created' => now()->getTimestamp(),
        'livemode' => false, 'data' => ['object' => ['id' => 'sub_entitlement_synthetic', 'customer' => $user->stripe_id,
            'livemode' => false, 'status' => 'active', 'items' => ['data' => []]]]];
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $observation = ['account_id' => 'acct_entitlement_synthetic', 'livemode' => false, 'customer_id' => $user->stripe_id,
        'customer_deleted' => false, 'fetch_started_at' => now()->getTimestamp(), 'fetch_completed_at' => now()->getTimestamp(),
        'subscriptions' => [['id' => 'sub_entitlement_synthetic', 'customer_id' => $user->stripe_id, 'livemode' => false,
            'type' => 'default', 'status' => 'active', 'created' => now()->getTimestamp() - 100,
            'trial_end' => null, 'cancel_at' => null, 'canceled_at' => null, 'ended_at' => null, 'cancel_at_period_end' => true,
            'items' => [['id' => 'si_entitlement_synthetic', 'price' => 'price_entitlement_synthetic',
                'product' => 'prod_entitlement_synthetic', 'quantity' => 1, 'current_period_end' => $end]]]]];
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn($observation);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);
    expect(app(BillingReconciler::class)->run($receipt->reconciliation_id))->toBe($expectedResult && $existingOrigin !== 'unknown')
        ->and($user->fresh()->hasRole('entitlement_synthetic'))->toBe($expectedResult || $existingOrigin !== null);

    return [$user->fresh(), $end, $receipt->fresh(), $observation];
}

function reconcileEntitlementObservation(User $user, array $observation, string $eventId): bool
{
    $event = ['id' => $eventId, 'type' => 'customer.subscription.updated', 'created' => now()->getTimestamp(),
        'livemode' => false, 'data' => ['object' => ['id' => 'sub_entitlement_synthetic', 'customer' => $user->stripe_id,
            'livemode' => false, 'status' => 'active', 'items' => ['data' => []]]]];
    $receipt = app(BillingWebhookAdmission::class)->admit($event, $event['type']);
    $gateway = Mockery::mock(BillingAuthoritativeGateway::class);
    $gateway->shouldReceive('fetch')->once()->andReturn($observation);
    app()->instance(BillingAuthoritativeGateway::class, $gateway);

    return app(BillingReconciler::class)->run($receipt->reconciliation_id);
}
