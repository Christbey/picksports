<?php

namespace App\Services\Predictions;

use App\Models\User;
use App\Support\PredictionDataPermissions;
use App\Support\PredictionFieldAccess;
use App\Support\UserTierResolver;

class PredictionAccessInspector
{
    public function __construct(
        private readonly PredictionFieldAccess $predictionFieldAccess,
        private readonly UserTierResolver $userTierResolver
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(User $user, ?string $sport = null): array
    {
        $tier = $this->userTierResolver->resolveTier($user);
        $tierDataPermissions = collect($tier?->data_permissions ?? [])
            ->filter(fn ($field) => is_string($field) && $field !== '')
            ->values()
            ->all();

        $effectiveAccess = [];
        foreach (PredictionDataPermissions::allFields() as $field) {
            $permissionName = PredictionDataPermissions::permissionForField($field);
            $tierAllows = in_array($field, $tierDataPermissions, true);
            $spatieAllows = $this->predictionFieldAccess->canViewField($user, $field);

            $effectiveAccess[$field] = [
                'permission' => $permissionName,
                'from_tier_data_permissions' => $tierAllows,
                'from_spatie_permissions' => $spatieAllows,
                'effective' => $spatieAllows,
            ];
        }

        return [
            'sport' => $sport,
            'user_id' => $user->id,
            'tier' => [
                'slug' => $tier?->slug,
                'name' => $tier?->name,
                'data_permissions' => $tierDataPermissions,
                'role_synced' => $tier ? $user->hasRole($tier->slug) : false,
            ],
            'role_names' => $user->getRoleNames()->values()->all(),
            'permission_names' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'effective_access' => $effectiveAccess,
        ];
    }
}
