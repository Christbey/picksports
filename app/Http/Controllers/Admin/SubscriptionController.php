<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserSubscriptionResource;
use App\Http\Resources\Admin\SubscriptionTierOptionResource;
use App\Models\SubscriptionTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

class SubscriptionController extends Controller
{
    private const USERS_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $users = $this->usersQuery($search)
            ->latest()
            ->paginate(self::USERS_PER_PAGE);

        $users->through(fn (User $user) => (new AdminUserSubscriptionResource($user))->resolve());

        $tiers = $this->tierOptions();

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
            return $this->backError('User does not have an active subscription.');
        }

        try {
            $subscription = $user->subscription();
            $this->refreshSubscriptionFromStripe($subscription);

            return $this->backSuccess("Subscription synced successfully for {$user->name}.");
        } catch (\Exception $e) {
            return $this->backError("Failed to sync subscription: {$e->getMessage()}");
        }
    }

    public function syncAll(): \Illuminate\Http\RedirectResponse
    {
        $subscriptions = Subscription::whereNotNull('stripe_id')->get();
        $synced = 0;
        $errors = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $this->refreshSubscriptionFromStripe($subscription);

                $synced++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $message = "Synced {$synced} subscriptions.";
        if ($errors > 0) {
            $message .= " {$errors} failed.";
        }

        return $this->backSuccess($message);
    }

    public function assignTier(Request $request, User $user): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'tier_slug' => 'required|exists:subscription_tiers,slug',
        ]);

        $tier = SubscriptionTier::where('slug', $request->tier_slug)->first();

        if (! $tier) {
            return $this->backError('Tier not found.');
        }

        try {
            // Sync the user's role based on the tier
            $user->syncRoles([$tier->slug]);

            return $this->backSuccess("Successfully assigned {$tier->name} tier to {$user->name}.");
        } catch (\Exception $e) {
            return $this->backError("Failed to assign tier: {$e->getMessage()}");
        }
    }

    private function refreshSubscriptionFromStripe(Subscription $subscription): void
    {
        $stripeSubscription = $subscription->asStripeSubscription();

        $subscription->stripe_status = $stripeSubscription->status;
        $subscription->stripe_price = $stripeSubscription->items->data[0]->price->id ?? null;
        $subscription->quantity = $stripeSubscription->quantity ?? 1;
        $subscription->trial_ends_at = $stripeSubscription->trial_end
            ? Carbon::createFromTimestamp($stripeSubscription->trial_end)
            : null;
        $subscription->ends_at = $stripeSubscription->cancel_at
            ? Carbon::createFromTimestamp($stripeSubscription->cancel_at)
            : null;
        $subscription->save();
    }

    private function usersQuery(?string $search): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->with(['subscriptions' => fn ($q) => $q->latest()])
            ->when($search, fn ($q) => $q->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function tierOptions(): array
    {
        return $this->resourcePayload(SubscriptionTierOptionResource::collection(
            SubscriptionTier::query()
                ->orderBy('price_monthly')
                ->get(['id', 'name', 'slug'])
        ));
    }
}
