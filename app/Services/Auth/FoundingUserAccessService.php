<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Settings\FoundingUsersSettingsService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class FoundingUserAccessService
{
    public function __construct(private readonly FoundingUsersSettingsService $foundingUsersSettingsService) {}

    public function assignFoundingRoleIfEligible(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $limit = $this->foundingUsersSettingsService->getLimit();
        if ($limit < 1) {
            return false;
        }

        $roleName = (string) config('founding_users.role', 'founding_user');
        if ($roleName === '') {
            return false;
        }

        $guardName = (string) config('auth.defaults.guard', 'web');
        Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => $guardName,
        ]);

        return (bool) DB::transaction(function () use ($user, $roleName, $guardName, $limit): bool {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guardName)
                ->lockForUpdate()
                ->firstOrFail();

            $modelRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
            $modelMorphKey = config('permission.column_names.model_morph_key', 'model_id');

            $assignedCount = DB::table($modelRolesTable)
                ->where('role_id', $role->id)
                ->where('model_type', User::class)
                ->count();

            if ($assignedCount >= $limit) {
                return false;
            }

            if (! $user->hasRole($role->name)) {
                DB::table($modelRolesTable)->insert([
                    'role_id' => $role->id,
                    'model_type' => User::class,
                    $modelMorphKey => $user->getKey(),
                ]);
            }

            return true;
        });
    }

    public function isEnabled(): bool
    {
        return (bool) config('founding_users.enabled', false);
    }
}
