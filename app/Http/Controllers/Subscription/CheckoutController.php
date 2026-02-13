<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __invoke(Request $request)
    {
        $tierSlugs = SubscriptionTier::active()->pluck('slug')->toArray();

        $request->validate([
            'tier' => ['required', Rule::in($tierSlugs)],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        $user = $request->user();
        $tierSlug = $request->input('tier');
        $billingPeriod = $request->input('billing_period');

        $tier = SubscriptionTier::where('slug', $tierSlug)->first();

        if (! $tier || $tier->is_default) {
            return back()->with('error', 'Cannot subscribe to the free tier.');
        }

        $stripePriceId = $billingPeriod === 'monthly'
            ? $tier->stripe_price_id_monthly
            : $tier->stripe_price_id_yearly;

        if (! $stripePriceId) {
            return back()->with('error', 'Invalid subscription tier or billing period.');
        }

        if ($user->subscribed()) {
            $user->subscription()->swapAndInvoice($stripePriceId);

            $user->syncRoleFromTier();

            return redirect()->route('subscription.manage')
                ->with('success', 'Your subscription has been updated successfully.');
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

        return redirect()->route('dashboard')
            ->with('success', 'Thank you for subscribing! Your subscription is now active.');
    }
}
