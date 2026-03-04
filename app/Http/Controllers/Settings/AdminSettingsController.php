<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Auth\FoundingUserAccessService;
use App\Services\Settings\FoundingUsersSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminSettingsController extends Controller
{
    public function __construct(private readonly FoundingUsersSettingsService $foundingUsersSettingsService) {}

    public function __invoke(Request $request): Response
    {
        $roleName = (string) config('founding_users.role', 'founding_user');
        $guardName = (string) config('auth.defaults.guard', 'web');
        $limit = $this->foundingUsersSettingsService->getLimit();
        $tierSlug = (string) config('founding_users.tier_slug', 'premium');
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', $guardName)
            ->first();

        $modelRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $modelMorphKey = config('permission.column_names.model_morph_key', 'model_id');

        $used = $role
            ? (int) DB::table($modelRolesTable)
                ->where('role_id', $role->id)
                ->where('model_type', User::class)
                ->count()
            : 0;

        $foundingUsers = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->whereHas('roles', function ($query) use ($roleName, $guardName) {
                $query->where('name', $roleName)->where('guard_name', $guardName);
            })
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('settings/Admin', [
            'foundingUsers' => [
                'enabled' => (bool) config('founding_users.enabled', false),
                'limit' => $limit,
                'used' => $used,
                'remaining' => max($limit - $used, 0),
                'role' => $roleName,
                'tier_slug' => $tierSlug,
                'tier_name' => SubscriptionTier::query()->where('slug', $tierSlug)->value('name') ?? $tierSlug,
                'users' => $foundingUsers,
            ],
        ]);
    }

    public function grantFoundingAccess(Request $request, FoundingUserAccessService $foundingUserAccessService): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $roleName = (string) config('founding_users.role', 'founding_user');
        $guardName = (string) config('auth.defaults.guard', 'web');

        Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => $guardName,
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();

        if ($user->hasRole($roleName)) {
            return $this->backWarning("{$user->email} already has founding access.");
        }

        $granted = $foundingUserAccessService->assignFoundingRoleIfEligible($user);

        if (! $granted) {
            if (! $foundingUserAccessService->isEnabled()) {
                return $this->backError('Founding users program is currently disabled.');
            }

            return $this->backError('No founding user slots are available.');
        }

        return $this->backSuccess("Granted founding access to {$user->email}.");
    }

    public function updateFoundingLimit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'limit' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        try {
            $this->foundingUsersSettingsService->setLimit((int) $validated['limit']);
        } catch (\RuntimeException $exception) {
            return $this->backError('Could not save founding user limit. Run `php artisan migrate` to create the settings table, then try again.');
        }

        return $this->backSuccess("Updated founding user limit to {$validated['limit']}.");
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '' || mb_strlen($query) < 2) {
            return response()->json([
                'users' => [],
            ]);
        }

        $roleName = (string) config('founding_users.role', 'founding_user');
        $guardName = (string) config('auth.defaults.guard', 'web');

        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->where(function ($builder) use ($query) {
                $builder->where('email', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%");
            })
            ->whereDoesntHave('roles', function ($queryBuilder) use ($roleName, $guardName) {
                $queryBuilder->where('name', $roleName)->where('guard_name', $guardName);
            })
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function revokeFoundingAccess(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $roleName = (string) config('founding_users.role', 'founding_user');
        $user = User::query()->findOrFail($validated['user_id']);

        if (! $user->hasRole($roleName)) {
            return $this->backWarning("{$user->email} does not have founding access.");
        }

        $user->removeRole($roleName);

        return $this->backSuccess("Revoked founding access for {$user->email}.");
    }
}
