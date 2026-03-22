<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionCheckoutService;
use App\Support\SubscriptionTierCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly SubscriptionTierCache $subscriptionTierCache,
        private readonly SubscriptionCheckoutService $subscriptionCheckoutService
    ) {}

    public function __invoke(Request $request)
    {
        $configuredTierSlugs = array_keys(config('subscriptions.tiers', []));
        $tierSlugs = $this->subscriptionTierCache->activeOrdered()->pluck('slug')->values()->all();
        $validTierSlugs = array_values(array_unique([...$configuredTierSlugs, ...$tierSlugs]));

        $request->validate([
            'tier' => ['required', Rule::in($validTierSlugs)],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

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

        if ($user->subscribed()) {
            $user->subscription()->swapAndInvoice($stripePriceId);

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
        $request->user()->syncRoleFromTier();

        return $this->redirectSuccess('dashboard', 'Thank you for subscribing! Your subscription is now active.');
    }
}
