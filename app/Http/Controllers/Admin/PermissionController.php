<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PermissionSummaryResource;
use App\Http\Resources\Admin\RolePermissionSummaryResource;
use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use App\Support\PredictionDataPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SPORT_SLUGS = ['nba', 'nfl', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

    public function __construct(private readonly TierPermissionSyncService $tierPermissionSyncService) {}

    public function index(Request $request): Response
    {
        $this->ensureCorePermissionsExist();

        $roleSummaries = $this->resourcePayload(RolePermissionSummaryResource::collection(
            Role::query()
                ->with('permissions')
                ->withCount('users')
                ->orderBy('name')
                ->get()
        ));
        $rolesByName = collect($roleSummaries)->keyBy('name');

        $permissions = $this->resourcePayload(PermissionSummaryResource::collection(
            Permission::query()
                ->orderBy('name')
                ->get()
        ));

        $tiers = SubscriptionTier::query()
            ->ordered()
            ->get()
            ->map(function (SubscriptionTier $tier) use ($rolesByName) {
                $roleSummary = $rolesByName->get($tier->slug);

                return [
                    'id' => $tier->id,
                    'name' => $tier->name,
                    'slug' => $tier->slug,
                    'users_count' => $roleSummary['users_count'] ?? 0,
                    'permissions' => $this->tierPermissionSyncService->resolvePermissionNamesForTier($tier),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Permissions', [
            'roles' => $roleSummaries,
            'tiers' => $tiers,
            'permissions' => $permissions,
        ]);
    }

    public function updateTierPermissions(Request $request, SubscriptionTier $tier): RedirectResponse
    {
        $this->ensureCorePermissionsExist();

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $tier->permissions = collect($validated['permissions'])
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->unique()
            ->values()
            ->all();
        $tier->save();

        $this->tierPermissionSyncService->syncTierRolePermissions($tier);

        return $this->backSuccess("Updated permissions for {$tier->name}.");
    }

    private function ensureCorePermissionsExist(): void
    {
        $guardName = config('auth.defaults.guard', 'web');

        foreach ($this->corePermissionNames() as $permissionName) {
            Permission::findOrCreate($permissionName, $guardName);
        }
    }

    /**
     * @return array<int, string>
     */
    private function corePermissionNames(): array
    {
        $sportPermissions = collect(self::SPORT_SLUGS)
            ->map(fn (string $sport) => "view-{$sport}-predictions")
            ->all();

        return array_merge($sportPermissions, [
            'export-predictions',
            'access-api',
            'access-advanced-analytics',
            'receive-email-alerts',
            'access-priority-support',
            'trigger-alerts',
            'view-alert-stats',
        ], PredictionDataPermissions::allPermissionNames());
    }
}
