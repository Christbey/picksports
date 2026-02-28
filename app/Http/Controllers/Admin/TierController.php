<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TierRequest;
use App\Http\Resources\Admin\NotificationTemplateAdminResource;
use App\Http\Resources\Admin\SubscriptionTierAdminResource;
use App\Models\NotificationTemplate;
use App\Models\SubscriptionTier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TierController extends Controller
{
    private const INDEX_ROUTE = 'admin.tiers.index';

    public function index(): Response
    {
        $tiers = $this->resourcePayload(SubscriptionTierAdminResource::collection(
            SubscriptionTier::query()
                ->ordered()
                ->get()
        ));

        $notificationTemplates = $this->resourcePayload(NotificationTemplateAdminResource::collection(
            NotificationTemplate::query()
                ->orderBy('name')
                ->get()
        ));

        return Inertia::render('Admin/Tiers/Index', [
            'tiers' => $tiers,
            'notificationTemplates' => $notificationTemplates,
        ]);
    }

    public function create(): Response
    {
        return $this->renderFormPage('Admin/Tiers/Form', 'tier');
    }

    public function store(TierRequest $request): RedirectResponse
    {
        $tier = SubscriptionTier::create($request->validated());

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
        $tier->update($request->validated());

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('updated', $tier->name));
    }

    public function destroy(SubscriptionTier $tier): RedirectResponse
    {
        $name = $tier->name;
        $tier->delete();

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('deleted', $name));
    }

    private function successMessage(string $action, string $name): string
    {
        return "Tier '{$name}' {$action} successfully.";
    }
}
