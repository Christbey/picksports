<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TierRequest;
use App\Http\Resources\Admin\SubscriptionTierAdminResource;
use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class TierController extends Controller
{
    private const INDEX_ROUTE = 'admin.tiers.index';

    public function __construct(private readonly TierPermissionSyncService $tierPermissionSyncService) {}

    public function index(): Response
    {
        $tiers = $this->resourcePayload(SubscriptionTierAdminResource::collection(
            SubscriptionTier::query()
                ->ordered()
                ->get()
        ));

        return Inertia::render('Admin/Tiers/Index', [
            'tiers' => $tiers,
        ]);
    }

    public function create(): Response
    {
        return $this->renderFormPage('Admin/Tiers/Form', 'tier');
    }

    public function store(TierRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        try {
            $tier = DB::transaction(function () use ($validated): SubscriptionTier {
                // Create rather than adopt: a concurrent independent role must never be overwritten.
                Role::query()->create(['name' => $validated['slug'], 'guard_name' => config('auth.defaults.guard', 'web')]);
                $tier = SubscriptionTier::query()->create($validated);
                $this->tierPermissionSyncService->syncTierRolePermissions($tier);

                return $tier;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['slug' => 'This tier identifier was claimed concurrently. Choose another identifier.']);
        }

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('created', $tier->name));
    }

    public function edit(SubscriptionTier $tier): Response
    {
        return $this->renderFormPage(
            'Admin/Tiers/Form',
            'tier',
            (new SubscriptionTierAdminResource($tier))->resolve()
        );
    }

    public function update(TierRequest $request, SubscriptionTier $tier): RedirectResponse
    {
        $validated = $request->validated();
        $tier = DB::transaction(function () use ($tier, $validated): SubscriptionTier {
            $locked = SubscriptionTier::query()->lockForUpdate()->findOrFail($tier->id);
            if ($validated['slug'] !== $locked->slug) {
                throw ValidationException::withMessages(['slug' => 'Tier identifiers cannot be renamed. Create a new tier and migrate users explicitly.']);
            }
            $locked->update($validated);
            $this->tierPermissionSyncService->syncTierRolePermissions($locked);

            return $locked;
        });

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('updated', $tier->name));
    }

    public function destroy(SubscriptionTier $tier): RedirectResponse
    {
        $name = $tier->name;
        DB::transaction(function () use ($tier): void {
            $locked = SubscriptionTier::query()->lockForUpdate()->findOrFail($tier->id);
            $configured = array_keys(config('subscriptions.tiers', []));
            $referenced = $locked->is_default
                || $locked->slug === config('subscriptions.default_tier', 'free')
                || in_array($locked->slug, $configured, true)
                || $locked->slug === config('founding_users.tier_slug', 'premium')
                || filled($locked->stripe_price_id_monthly)
                || filled($locked->stripe_price_id_yearly)
                || Role::query()->where('name', $locked->slug)->lockForUpdate()->first() !== null;
            if ($referenced) {
                throw ValidationException::withMessages(['tier' => 'This tier has access or billing references and cannot be deleted safely. Deactivate it, then plan an explicit user and billing migration.']);
            }
            $locked->delete();
        });

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('deleted', $name));
    }

    private function successMessage(string $action, string $name): string
    {
        return "Tier '{$name}' {$action} successfully.";
    }
}
