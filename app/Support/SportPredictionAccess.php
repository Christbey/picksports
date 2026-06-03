<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SportPredictionAccess
{
    /**
     * @return array<int, string>
     */
    public function sportSlugs(): array
    {
        return array_keys((array) config('sports.domains', []));
    }

    public function permissionNameForSport(string $sport): string
    {
        return 'view-'.strtolower($sport).'-predictions';
    }

    /**
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        return collect($this->sportSlugs())
            ->map(fn (string $sport): string => $this->permissionNameForSport($sport))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $sports
     * @return array<int, string>
     */
    public function permissionNamesForSports(array $sports): array
    {
        return collect($sports)
            ->filter(fn ($sport): bool => is_string($sport) && $sport !== '')
            ->map(fn (string $sport): string => strtolower($sport))
            ->intersect($this->sportSlugs())
            ->map(fn (string $sport): string => $this->permissionNameForSport($sport))
            ->values()
            ->all();
    }

    public function canView(?User $user, string $sport): bool
    {
        $sport = strtolower($sport);

        if (app(TierAccessBypass::class)->shouldBypassTierChecks($user)) {
            return true;
        }

        if (! (bool) data_get(config('sports.domains'), "{$sport}.web.requires_prediction_permission", true)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        try {
            return $user->hasPermissionTo($this->permissionNameForSport($sport));
        } catch (GuardDoesNotMatch|PermissionDoesNotExist) {
            return false;
        }
    }
}
