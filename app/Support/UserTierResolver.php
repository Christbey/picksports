<?php

namespace App\Support;

use App\Models\SubscriptionTier;
use App\Models\User;

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

        $roleTier = $this->resolveTierFromRoles($user);
        if ($roleTier) {
            return $roleTier;
        }

        return $user->subscriptionTier();
    }

    private function resolveTierFromRoles(User $user): ?SubscriptionTier
    {
        $roleNames = $user->getRoleNames()->filter(fn ($name) => is_string($name) && $name !== '')->values();
        if ($roleNames->isEmpty()) {
            return null;
        }

        return $this->subscriptionTierCache
            ->activeOrdered()
            ->whereIn('slug', $roleNames->all())
            ->last();
    }
}
