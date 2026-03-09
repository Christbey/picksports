<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Support\SubscriptionTierCache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionTierCache $subscriptionTierCache) {}

    public function plans(Request $request): Response
    {
        $user = $request->user();
        $currentTier = $user ? $user->subscriptionTier() : null;

        $tiers = $this->subscriptionTierCache->activeOrdered()->map(function ($tier) use ($currentTier) {
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
        $tier = $user->subscriptionTier();

        if ($user->hasFoundingAccess()) {
            return Inertia::render('Subscription/Manage', [
                'subscription' => [
                    'tier' => $tier?->name,
                    'status' => 'founding_access',
                    'current_period_end' => null,
                    'cancel_at_period_end' => false,
                ],
            ]);
        }

        if (! $user->subscribed()) {
            return redirect()->route('subscription.plans');
        }

        $subscription = $user->subscription();

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

        if ($user->hasFoundingAccess()) {
            return $this->redirectSuccess('subscription.manage', 'Founding accounts do not require billing.');
        }

        if (! $user->subscribed()) {
            return redirect()->route('subscription.plans');
        }

        $user->subscription()->cancel();

        return $this->redirectSuccess(
            'subscription.manage',
            'Your subscription has been cancelled and will end at the end of your billing period.'
        );
    }

    public function resume(Request $request)
    {
        $user = $request->user();

        if ($user->hasFoundingAccess()) {
            return $this->redirectSuccess('subscription.manage', 'Founding accounts do not require billing.');
        }

        if (! $user->subscribed() || ! $user->subscription()->cancelled()) {
            return redirect()->route('subscription.plans');
        }

        $user->subscription()->resume();

        return $this->redirectSuccess('subscription.manage', 'Your subscription has been resumed successfully.');
    }
}
