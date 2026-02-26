<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $users = User::query()
            ->with(['subscriptions' => fn ($q) => $q->latest()])
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->latest()
            ->paginate(20);

        $users->through(function ($user) {
            $subscription = $user->subscriptions->first();
            $tier = $user->subscriptionTier();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tier' => $tier?->name ?? 'Unknown',
                'subscription' => $subscription ? [
                    'stripe_id' => $subscription->stripe_id,
                    'stripe_status' => $subscription->stripe_status,
                    'stripe_price' => $subscription->stripe_price,
                    'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
                    'ends_at' => $subscription->ends_at?->toDateTimeString(),
                    'created_at' => $subscription->created_at?->toDateTimeString(),
                ] : null,
            ];
        });

        $tiers = SubscriptionTier::orderBy('price_monthly')->get(['id', 'name', 'slug']);

        return Inertia::render('Admin/Subscriptions', [
            'users' => $users,
            'tiers' => $tiers,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function sync(User $user): \Illuminate\Http\RedirectResponse
    {
        if (! $user->subscribed()) {
            return back()->with('error', 'User does not have an active subscription.');
        }

        try {
            $subscription = $user->subscription();
            $stripeSubscription = $subscription->asStripeSubscription();

            $subscription->stripe_status = $stripeSubscription->status;
            $subscription->stripe_price = $stripeSubscription->items->data[0]->price->id ?? null;
            $subscription->quantity = $stripeSubscription->quantity ?? 1;
            $subscription->trial_ends_at = $stripeSubscription->trial_end ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null;
            $subscription->ends_at = $stripeSubscription->cancel_at ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) : null;
            $subscription->save();

            return back()->with('success', "Subscription synced successfully for {$user->name}.");
        } catch (\Exception $e) {
            return back()->with('error', "Failed to sync subscription: {$e->getMessage()}");
        }
    }

    public function syncAll(): \Illuminate\Http\RedirectResponse
    {
        $subscriptions = Subscription::whereNotNull('stripe_id')->get();
        $synced = 0;
        $errors = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $stripeSubscription = $subscription->asStripeSubscription();

                $subscription->stripe_status = $stripeSubscription->status;
                $subscription->stripe_price = $stripeSubscription->items->data[0]->price->id ?? null;
                $subscription->quantity = $stripeSubscription->quantity ?? 1;
                $subscription->trial_ends_at = $stripeSubscription->trial_end ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) : null;
                $subscription->ends_at = $stripeSubscription->cancel_at ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) : null;
                $subscription->save();

                $synced++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $message = "Synced {$synced} subscriptions.";
        if ($errors > 0) {
            $message .= " {$errors} failed.";
        }

        return back()->with('success', $message);
    }

    public function assignTier(Request $request, User $user): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'tier_slug' => 'required|exists:subscription_tiers,slug',
        ]);

        $tier = SubscriptionTier::where('slug', $request->tier_slug)->first();

        if (! $tier) {
            return back()->with('error', 'Tier not found.');
        }

        try {
            // Sync the user's role based on the tier
            $user->syncRoles([$tier->slug]);

            return back()->with('success', "Successfully assigned {$tier->name} tier to {$user->name}.");
        } catch (\Exception $e) {
            return back()->with('error', "Failed to assign tier: {$e->getMessage()}");
        }
    }
}
