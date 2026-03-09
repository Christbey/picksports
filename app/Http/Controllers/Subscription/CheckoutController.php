<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Support\SubscriptionTierCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(private readonly SubscriptionTierCache $subscriptionTierCache) {}

    public function __invoke(Request $request)
    {
        $tierSlugs = $this->subscriptionTierCache->activeOrdered()->pluck('slug')->values()->all();

        $request->validate([
            'tier' => ['required', Rule::in($tierSlugs)],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        $user = $request->user();
        $tierSlug = $request->input('tier');
        $billingPeriod = $request->input('billing_period');

        if ($user->hasFoundingAccess()) {
            return $this->backError('Your account already has founding access and does not require a paid subscription.');
        }

        $tier = $this->subscriptionTierCache->tierBySlug((string) $tierSlug);

        if (! $tier || $tier->is_default) {
            return $this->backError('Cannot subscribe to the free tier.');
        }

        $stripePriceId = $billingPeriod === 'monthly'
            ? $tier->stripe_price_id_monthly
            : $tier->stripe_price_id_yearly;

        if (! $stripePriceId) {
            return $this->backError('Invalid subscription tier or billing period.');
        }

        if ($user->subscribed()) {
            $user->subscription()->swapAndInvoice($stripePriceId);

            $user->syncRoleFromTier();

            return $this->redirectSuccess('subscription.manage', 'Your subscription has been updated successfully.');
        }

        $checkout = $user->newSubscription('default', $stripePriceId)
            ->checkout([
                'success_url' => route('subscription.success'),
                'cancel_url' => route('subscription.plans'),
            ]);

        return inertia()->location($checkout->url);
    }

    public function success(Request $request)
    {
        $request->user()->syncRoleFromTier();

        return $this->redirectSuccess('dashboard', 'Thank you for subscribing! Your subscription is now active.');
    }
}
