<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionAssignTierRequest;
use App\Http\Resources\Admin\AdminUserSubscriptionResource;
use App\Http\Resources\Admin\SubscriptionTierOptionResource;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use App\Services\Billing\BillingOperatorAdmission;
use App\Services\Billing\BillingRoleEntitlements;
use App\Support\SubscriptionTierCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function sync(Request $request, User $user): RedirectResponse
    {
        if (! config('billing.durable.enabled', false)) {
            return $this->backError('Durable billing reconciliation must be configured before requesting a subscription refresh.');
        }
        try {
            $admission = app(BillingOperatorAdmission::class);
            $plans = $admission->plan([], [(int) $user->id], []);
            $admission->admit($plans[0], 'Admin requested subscription reconciliation', $request->user());

            return $this->backSuccess('Subscription reconciliation requested. The scheduler will process the durable request.');
        } catch (\Throwable) {
            return $this->backError('Subscription reconciliation could not be requested. Resolve the billing linkage or attention hold and retry.');
        }
    }

    public function syncAll(Request $request): RedirectResponse
    {
        $validated = $request->validate(['after_user_id' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        if (! config('billing.durable.enabled', false)) {
            return $this->backError('Durable billing reconciliation must be configured before requesting a bounded batch.');
        }
        $ids = User::query()->whereNotNull('stripe_id')->where('id', '>', (int) ($validated['after_user_id'] ?? 0))
            ->orderBy('id')->limit((int) ($validated['limit'] ?? 20))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return $this->backSuccess('No billing customers remain after this cursor.');
        }
        $admitted = 0;
        try {
            $admission = app(BillingOperatorAdmission::class);
            $plans = $admission->plan([], $ids, []); // All bindings validate before the first admission.
            foreach ($plans as $plan) {
                $admission->admit($plan, 'Admin requested bounded subscription reconciliation', $request->user());
                $admitted++;
            }

            return $this->backSuccess('Requested '.$admitted.' customer reconciliations. Next cursor: '.max($ids).'.');
        } catch (\Throwable) {
            return $this->backError('Requested '.$admitted.' customer reconciliations before the batch stopped. Resolve invalid linkage or attention holds; no provider calls were made.');
        }
    }

    public function assignTier(SubscriptionAssignTierRequest $request, User $user): RedirectResponse
    {
        $request->validated();

        $tier = $this->subscriptionTierCache->tierBySlug((string) $request->tier_slug);

        if (! $tier) {
            return $this->backError('Tier not found.');
        }

        try {
            if (config('billing.entitlements.enabled', false)
                || app(BillingRoleEntitlements::class)->hasRecorded($user)) {
                app(BillingRoleEntitlements::class)->grantManualTier($user, $tier, $request->user());
            } else {
                $role = $this->tierPermissionSyncService->syncTierRolePermissions($tier);
                $tierSlugs = $this->subscriptionTierCache->allSlugs();
                $nonTierRoles = $user->getRoleNames()
                    ->reject(fn ($name) => in_array($name, $tierSlugs, true))
                    ->values()
                    ->all();

                $user->syncRoles(array_values(array_unique([...$nonTierRoles, $role->name])));

            }

            return $this->backSuccess("Successfully assigned {$tier->name} tier to {$user->name}.");
        } catch (\Exception $e) {
            return $this->backError("Failed to assign tier: {$e->getMessage()}");
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
