<?php

namespace App\Services\Api\V2;

use App\Models\SubscriptionTier;
use App\Models\User;
use App\Support\TierAccessBypass;
use App\Support\UserTierResolver;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SportApiAccess
{
    public function __construct(
        private readonly TierAccessBypass $tierAccessBypass,
        private readonly UserTierResolver $userTierResolver,
    ) {}

    public function shouldBypass(?User $user): bool
    {
        return $this->tierAccessBypass->shouldBypassTierChecks($user);
    }

    public function canAccessApi(User $user): bool
    {
        if (! (bool) config('subscriptions.features.api_enabled', true)) {
            return false;
        }

        $tier = $this->userTierResolver->resolveTier($user);

        if ($tier && $this->tierGrantsApiAccess($tier)) {
            return true;
        }

        return $this->userHasPermission($user, 'access-api');
    }

    public function canAccessSport(User $user, string $sport): bool
    {
        $sport = strtolower(trim($sport));
        if ($sport === '') {
            return false;
        }

        $tier = $this->userTierResolver->resolveTier($user);

        if ($tier && $this->tierGrantsSportAccess($tier, $sport)) {
            return true;
        }

        return $this->userHasPermission($user, "view-{$sport}-predictions");
    }

    private function tierGrantsApiAccess(SubscriptionTier $tier): bool
    {
        if ((bool) data_get($tier->features ?? [], 'api_access', false)) {
            return true;
        }

        return in_array('access-api', $tier->permissions ?? [], true);
    }

    private function tierGrantsSportAccess(SubscriptionTier $tier, string $sport): bool
    {
        $allowedSports = collect((array) data_get($tier->features ?? [], 'sports_access', []))
            ->map(fn ($allowedSport): string => strtolower((string) $allowedSport))
            ->all();

        if (in_array($sport, $allowedSports, true)) {
            return true;
        }

        return in_array("view-{$sport}-predictions", $tier->permissions ?? [], true);
    }

    private function userHasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (GuardDoesNotMatch|PermissionDoesNotExist) {
            return false;
        }
    }
}
