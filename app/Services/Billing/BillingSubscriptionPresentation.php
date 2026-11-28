<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\SubscriptionTier;
use Laravel\Cashier\Subscription;

/** Local observations only; cancellation time is not a renewal-date substitute. */
final class BillingSubscriptionPresentation
{
    public function present(Subscription $subscription, ?string $effectiveTier): array
    {
        $canonical = $subscription instanceof BillingSubscription && $subscription->billing_projection_attempt_id !== null;
        $period = $canonical && $subscription->billing_current_period_end_epoch !== null
            ? BillingClock::fromEpoch((int) $subscription->billing_current_period_end_epoch)->toIso8601String()
            : null;
        $cancelAtEnd = $canonical ? (bool) $subscription->billing_cancel_at_period_end : $subscription->ends_at !== null;

        return ['tier' => $effectiveTier, 'billing_tier' => $this->billingTier($subscription), 'status' => $subscription->stripe_status,
            'current_period_end' => $period, 'cancel_at_period_end' => $cancelAtEnd,
            'scheduled_access_end' => $subscription->ends_at?->toIso8601String(),
            'period_end_source' => $period !== null ? 'canonical_provider_observation' : 'unavailable',
            'can_cancel' => in_array($subscription->stripe_status, ['active', 'trialing', 'past_due', 'unpaid'], true) && ! $cancelAtEnd,
            'can_resume' => $cancelAtEnd && $subscription->onGracePeriod()];
    }

    public function unresolved(?string $effectiveTier): array
    {
        return ['tier' => $effectiveTier, 'billing_tier' => null, 'status' => 'billing_review', 'current_period_end' => null,
            'scheduled_access_end' => null, 'cancel_at_period_end' => false, 'can_cancel' => false, 'can_resume' => false,
            'period_end_source' => 'unavailable'];
    }

    private function billingTier(Subscription $subscription): ?string
    {
        if (! is_string($subscription->stripe_price)) {
            return null;
        }
        $query = SubscriptionTier::query();
        $mysql = $query->getModel()->getConnection()->getDriverName() === 'mysql';
        $condition = $mysql ? 'CAST(stripe_price_id_monthly AS BINARY) = ? OR CAST(stripe_price_id_yearly AS BINARY) = ?'
            : 'stripe_price_id_monthly COLLATE BINARY = ? OR stripe_price_id_yearly COLLATE BINARY = ?';
        $rows = $query->whereRaw('('.$condition.')', [$subscription->stripe_price, $subscription->stripe_price])->limit(2)->get();

        return $rows->count() === 1 ? $rows->first()->name : null;
    }
}
