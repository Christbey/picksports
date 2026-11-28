<?php

namespace App\Support;

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingRoleEntitlements;

class UserTierResolver
{
    public function __construct(private readonly SubscriptionTierCache $subscriptionTierCache) {}

    public function resolveTierSlug(?User $user): string
    {
        return $this->resolveTier($user)?->slug ?? config('subscriptions.default_tier', 'free');
    }

    public function resolveTier(?User $user): ?SubscriptionTier
    {
        if (! $user) {
            return $this->subscriptionTierCache->defaultTier();
        }

        if ($user->hasFoundingAccess() && $user->foundingTier()) {
            return $user->foundingTier();
        }

        $entitlements = app(BillingRoleEntitlements::class);
        if ($entitlements->hasRecorded($user) && ($manual = $entitlements->manualOverrideTier($user)) !== null) {
            return $manual;
        }

        $roleTier = $this->resolveTierFromRoles($user);
        if ($roleTier) {
            return $roleTier;
        }

        $entitlements = app(BillingRoleEntitlements::class);

        return $entitlements->hasRecorded($user)
            ? ($entitlements->currentCanonicalTier($user) ?? $this->subscriptionTierCache->defaultTier())
            : $user->subscriptionTier();
    }

    private function resolveTierFromRoles(User $user): ?SubscriptionTier
    {
        $entitlements = app(BillingRoleEntitlements::class);
        $roleNames = ($entitlements->hasRecorded($user)
            ? $entitlements->effectiveRoles($user)->pluck('name') : $user->getRoleNames())->filter(fn ($name) => is_string($name) && $name !== '')->values();
        if ($roleNames->isEmpty()) {
            return null;
        }

        return $this->subscriptionTierCache
            ->activeOrdered()
            ->whereIn('slug', $roleNames->all())
            ->last();
    }
}
