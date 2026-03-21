<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use App\Services\Auth\FoundingUserAccessService;
use App\Services\Settings\FoundingUsersSettingsService;
use App\Support\SubscriptionTierCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly FoundingUsersSettingsService $foundingUsersSettingsService,
        private readonly SubscriptionTierCache $subscriptionTierCache,
    ) {}

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

        $groups = Group::query()
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->with(['users:id,name,email', 'brackets:id,group_id,name', 'joinLink'])
            ->latest()
            ->get()
            ->map(function (Group $group) {
                $invitations = GroupInvitation::query()
                    ->where('group_id', $group->id)
                    ->latest()
                    ->limit(20)
                    ->get()
                    ->map(fn (GroupInvitation $invitation) => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                        'token' => $invitation->token,
                        'invite_url' => URL::route('group-invitations.show', ['token' => $invitation->token]),
                        'accepted_at' => $invitation->accepted_at?->toDateTimeString(),
                        'expires_at' => $invitation->expires_at?->toDateTimeString(),
                        'created_at' => $invitation->created_at?->toDateTimeString(),
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $group->id,
                    'public_id' => $group->public_id,
                    'name' => $group->name,
                    'season' => $group->season,
                    'members' => $group->users
                        ->map(fn (User $user) => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'role' => (string) ($user->pivot?->role ?? 'member'),
                            'joined_at' => $user->pivot?->joined_at
                                ? Carbon::parse($user->pivot->joined_at)->toDateTimeString()
                                : null,
                        ])
                        ->sortBy([
                            fn (array $member) => $member['role'] === 'owner' ? 0 : 1,
                            'name',
                        ])
                        ->values()
                        ->all(),
                    'members_count' => $group->users->count(),
                    'brackets_count' => $group->brackets->count(),
                    'join_link' => $group->joinLink && $group->joinLink->isActive() ? [
                        'token' => $group->joinLink->token,
                        'join_url' => URL::route('groups.join.show', ['token' => $group->joinLink->token]),
                        'expires_at' => $group->joinLink->expires_at?->toDateTimeString(),
                        'created_at' => $group->joinLink->created_at?->toDateTimeString(),
                    ] : null,
                    'invitations' => $invitations,
                ];
            })
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
                'tier_name' => $this->subscriptionTierCache->tierBySlug($tierSlug)?->name ?? $tierSlug,
                'users' => $foundingUsers,
            ],
            'groups' => $groups,
        ]);
    }

    public function createGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $group = Group::query()->create([
            'owner_id' => $request->user()->id,
            'name' => $validated['name'],
            'type' => 'bracket_pool',
            'sport' => 'cbb',
            'season' => (int) $validated['season'],
        ]);

        $group->users()->attach($request->user()->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        GroupJoinLink::query()->create([
            'group_id' => $group->id,
            'created_by' => $request->user()->id,
        ]);

        return $this->backSuccess("Created group {$group->name}.");
    }

    public function rotateJoinLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
        ]);

        $group = Group::query()
            ->where('id', $validated['group_id'])
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->firstOrFail();

        $joinLink = GroupJoinLink::query()->firstOrNew([
            'group_id' => $group->id,
        ]);

        $joinLink->forceFill([
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'created_by' => $request->user()->id,
            'revoked_at' => null,
        ])->save();

        return $this->backSuccess("Updated shared join link for {$group->name}.");
    }

    public function inviteToGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'email' => ['required', 'email'],
        ]);

        $group = Group::query()
            ->where('id', $validated['group_id'])
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->firstOrFail();

        GroupInvitation::query()->updateOrCreate(
            [
                'group_id' => $group->id,
                'email' => strtolower($validated['email']),
            ],
            [
                'invited_by' => $request->user()->id,
                'accepted_by' => null,
                'accepted_at' => null,
                'expires_at' => now()->addDays(14),
            ],
        );

        return $this->backSuccess("Created invite for {$validated['email']}.");
    }

    public function searchGroupUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'query' => ['nullable', 'string'],
        ]);

        $query = trim((string) ($validated['query'] ?? ''));
        if ($query === '' || mb_strlen($query) < 2) {
            return response()->json([
                'users' => [],
            ]);
        }

        $group = Group::query()
            ->where('id', $validated['group_id'])
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->firstOrFail();

        $memberIds = $group->users()->pluck('users.id');

        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->where(function ($builder) use ($query) {
                $builder->where('email', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%");
            })
            ->whereNotIn('id', $memberIds)
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

    public function addUserToGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $group = Group::query()
            ->where('id', $validated['group_id'])
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->firstOrFail();

        $user = User::query()->findOrFail($validated['user_id']);

        if ($group->users()->where('users.id', $user->id)->exists()) {
            return $this->backWarning("{$user->email} is already in {$group->name}.");
        }

        $group->users()->attach($user->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return $this->backSuccess("Added {$user->email} to {$group->name}.");
    }

    public function removeUserFromGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $group = Group::query()
            ->where('id', $validated['group_id'])
            ->where('owner_id', $request->user()->id)
            ->where('type', 'bracket_pool')
            ->where('sport', 'cbb')
            ->firstOrFail();

        $user = User::query()->findOrFail($validated['user_id']);

        if ((int) $group->owner_id === (int) $user->id) {
            return $this->backError('Group owners cannot be removed from their own group.');
        }

        if (! $group->users()->where('users.id', $user->id)->exists()) {
            return $this->backWarning("{$user->email} is not in {$group->name}.");
        }

        $group->users()->detach($user->id);

        CbbBracket::query()
            ->where('user_id', $user->id)
            ->where('group_id', $group->id)
            ->update([
                'group_id' => null,
            ]);

        return $this->backSuccess("Removed {$user->email} from {$group->name}.");
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
