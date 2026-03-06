<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class TierAccessBypass
{
    public function tiersEnforced(): bool
    {
        return (bool) config('subscriptions.enforce_tiers', false);
    }

    /**
     * @return array<int, int>
     */
    public function bypassUserIds(): array
    {
        return collect(config('subscriptions.tier_bypass_user_ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function userIsBypassed(?Authenticatable $user): bool
    {
        if (! $this->tiersEnforced() || ! $user) {
            return false;
        }

        return in_array((int) $user->getAuthIdentifier(), $this->bypassUserIds(), true);
    }

    public function shouldBypassTierChecks(?Authenticatable $user): bool
    {
        return ! $this->tiersEnforced() || $this->userIsBypassed($user);
    }
}

