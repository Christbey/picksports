<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TierRequest;
use App\Models\SubscriptionTier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TierController extends Controller
{
    public function index(): Response
    {
        $tiers = SubscriptionTier::query()
            ->ordered()
            ->get();

        $notificationTemplates = \App\Models\NotificationTemplate::query()
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Tiers/Index', [
            'tiers' => $tiers,
            'notificationTemplates' => $notificationTemplates,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tiers/Form', [
            'tier' => null,
        ]);
    }

    public function store(TierRequest $request): RedirectResponse
    {
        $tier = SubscriptionTier::create($request->validated());

        return redirect()
            ->route('admin.tiers.index')
            ->with('success', "Tier '{$tier->name}' created successfully.");
    }

    public function edit(SubscriptionTier $tier): Response
    {
        return Inertia::render('Admin/Tiers/Form', [
            'tier' => $tier,
        ]);
    }

    public function update(TierRequest $request, SubscriptionTier $tier): RedirectResponse
    {
        $tier->update($request->validated());

        return redirect()
            ->route('admin.tiers.index')
            ->with('success', "Tier '{$tier->name}' updated successfully.");
    }

    public function destroy(SubscriptionTier $tier): RedirectResponse
    {
        $name = $tier->name;
        $tier->delete();

        return redirect()
            ->route('admin.tiers.index')
            ->with('success', "Tier '{$name}' deleted successfully.");
    }
}
