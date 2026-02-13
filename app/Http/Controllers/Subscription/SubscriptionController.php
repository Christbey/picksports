<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function plans(Request $request): Response
    {
        $user = $request->user();
        $currentTier = $user ? $user->subscriptionTier() : null;

        $tiers = SubscriptionTier::active()->ordered()->get()->map(function ($tier) use ($currentTier) {
            return [
                'id' => $tier->slug,
                'name' => $tier->name,
                'description' => $tier->description,
                'price' => [
                    'monthly' => $tier->price_monthly,
                    'yearly' => $tier->price_yearly,
                ],
                'features' => $tier->features,
                'is_current' => $currentTier && $tier->id === $currentTier->id,
            ];
        });

        return Inertia::render('Subscription/Plans', [
            'tiers' => $tiers,
            'currentTier' => $currentTier?->slug,
        ]);
    }

    public function manage(Request $request): Response
    {
        $user = $request->user();

        if (! $user->subscribed()) {
            return redirect()->route('subscription.plans');
        }

        $subscription = $user->subscription();
        $tier = $user->subscriptionTier();

        return Inertia::render('Subscription/Manage', [
            'subscription' => [
                'tier' => $tier?->name,
                'status' => $subscription->stripe_status,
                'current_period_end' => $subscription->ends_at,
                'cancel_at_period_end' => $subscription->ends_at !== null,
            ],
        ]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();

        if (! $user->subscribed()) {
            return redirect()->route('subscription.plans');
        }

        $user->subscription()->cancel();

        return redirect()->route('subscription.manage')
            ->with('success', 'Your subscription has been cancelled and will end at the end of your billing period.');
    }

    public function resume(Request $request)
    {
        $user = $request->user();

        if (! $user->subscribed() || ! $user->subscription()->cancelled()) {
            return redirect()->route('subscription.plans');
        }

        $user->subscription()->resume();

        return redirect()->route('subscription.manage')
            ->with('success', 'Your subscription has been resumed successfully.');
    }
}
