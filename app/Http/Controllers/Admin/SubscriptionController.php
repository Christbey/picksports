<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserSubscriptionResource;
use App\Http\Resources\Admin\SubscriptionTierOptionResource;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use App\Support\SubscriptionTierCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

class SubscriptionController extends Controller
{
    private const USERS_PER_PAGE = 20;

    public function __construct(
        private readonly TierPermissionSyncService $tierPermissionSyncService,
        private readonly SubscriptionTierCache $subscriptionTierCache,
    ) {}

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

    public function sync(User $user): RedirectResponse
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

    public function syncAll(): RedirectResponse
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

    public function assignTier(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'tier_slug' => 'required|exists:subscription_tiers,slug',
        ]);

        $tier = $this->subscriptionTierCache->tierBySlug((string) $request->tier_slug);

        if (! $tier) {
            return $this->backError('Tier not found.');
        }

        try {
            $role = $this->tierPermissionSyncService->syncTierRolePermissions($tier);
            $tierSlugs = $this->subscriptionTierCache->allSlugs();
            $nonTierRoles = $user->getRoleNames()
                ->reject(fn ($name) => in_array($name, $tierSlugs, true))
                ->values()
                ->all();

            $user->syncRoles(array_values(array_unique([...$nonTierRoles, $role->name])));

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

        $user = $subscription->owner;
        if ($user instanceof User) {
            $user->syncRoleFromTier();
        }
    }

    private function usersQuery(?string $search): Builder
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
            $this->subscriptionTierCache
                ->activeOrdered()
                ->sortBy('price_monthly')
                ->values()
        ));
    }
}
