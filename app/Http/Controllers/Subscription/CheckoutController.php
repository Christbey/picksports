<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CheckoutRequest;
use App\Services\Billing\BillingFinancialAdmission;
use App\Services\Billing\BillingLegacyOperation;
use App\Services\SubscriptionCheckoutService;
use App\Support\SubscriptionTierCache;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly SubscriptionTierCache $subscriptionTierCache,
        private readonly SubscriptionCheckoutService $subscriptionCheckoutService
    ) {}

    public function __invoke(CheckoutRequest $request)
    {
        $user = $request->user();
        $tierSlug = $request->input('tier');
        $billingPeriod = $request->input('billing_period');

        if ($user->hasFoundingAccess()) {
            return $this->backError('Your account already has founding access and does not require a paid subscription.');
        }

        $tier = $this->subscriptionTierCache->tierBySlug((string) $tierSlug);

        if (($tier?->is_default) || (! $tier && $tierSlug === (string) config('subscriptions.default_tier', 'free'))) {
            return redirect()
                ->route('subscription.plans')
                ->with('error', 'Cannot subscribe to the free tier.');
        }

        $stripePriceId = $billingPeriod === 'monthly'
            ? ($tier?->stripe_price_id_monthly ?? config("subscriptions.tiers.{$tierSlug}.stripe_price_id.monthly"))
            : ($tier?->stripe_price_id_yearly ?? config("subscriptions.tiers.{$tierSlug}.stripe_price_id.yearly"));

        if (! $stripePriceId) {
            return $this->backError('Invalid subscription tier or billing period.');
        }

        if (config('billing.durable.enabled', false)) {
            $intent = app(BillingFinancialAdmission::class)->admit($user, $user->subscribed() ? 'swap' : 'checkout', $stripePriceId);

            return redirect()->route('subscription.operation', ['intent' => $intent->id]);
        }

        if ($user->subscribed()) {
            app(BillingLegacyOperation::class)->run($user, 'swap', fn () => $user->subscription()->swapAndInvoice($stripePriceId));

            $user->syncRoleFromTier();

            return $this->redirectSuccess('subscription.manage', 'Your subscription has been updated successfully.');
        }

        $checkoutUrl = $this->subscriptionCheckoutService->createCheckoutUrl($user, $stripePriceId, [
            'success_url' => route('subscription.success'),
            'cancel_url' => route('subscription.plans'),
        ]);

        return inertia()->location($checkoutUrl);
    }

    public function success(Request $request)
    {
        return $this->redirectSuccess('subscription.manage', 'Checkout returned. Payment and access remain subject to confirmed billing reconciliation.');
    }
}
