<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class PredictionFieldAccess
{
    public function canViewField(User $user, string $fieldOrPermission): bool
    {
        if (app(TierAccessBypass::class)->shouldBypassTierChecks($user)) {
            return true;
        }

        $permissionName = $this->resolvePermissionName($fieldOrPermission);
        if ($permissionName === null) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permissionName);
        } catch (PermissionDoesNotExist|GuardDoesNotMatch) {
            return false;
        }
    }

    public function resolvePermissionName(string $fieldOrPermission): ?string
    {
        $mapped = PredictionDataPermissions::permissionForField($fieldOrPermission);
        if ($mapped !== null) {
            return $mapped;
        }

        return PredictionDataPermissions::fieldForPermission($fieldOrPermission) !== null
            ? $fieldOrPermission
            : null;
    }
}
