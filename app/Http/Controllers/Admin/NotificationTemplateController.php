<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NotificationTemplateRequest;
use App\Models\NotificationTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationTemplateController extends Controller
{
    public function index(): Response
    {
        $templates = NotificationTemplate::query()
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/NotificationTemplates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/NotificationTemplates/Form', [
            'template' => null,
            'availableVariables' => \App\Services\NotificationVariableRegistry::forAdminUI(),
        ]);
    }

    public function store(NotificationTemplateRequest $request): RedirectResponse
    {
        $template = NotificationTemplate::create($request->validated());

        return redirect()
            ->route('admin.notification-templates.index')
            ->with('success', "Template '{$template->name}' created successfully.");
    }

    public function edit(NotificationTemplate $notificationTemplate): Response
    {
        return Inertia::render('Admin/NotificationTemplates/Form', [
            'template' => $notificationTemplate,
            'availableVariables' => \App\Services\NotificationVariableRegistry::forAdminUI(),
        ]);
    }

    public function update(NotificationTemplateRequest $request, NotificationTemplate $notificationTemplate): RedirectResponse
    {
        $notificationTemplate->update($request->validated());

        return redirect()
            ->route('admin.notification-templates.index')
            ->with('success', "Template '{$notificationTemplate->name}' updated successfully.");
    }

    public function destroy(NotificationTemplate $notificationTemplate): RedirectResponse
    {
        $name = $notificationTemplate->name;
        $notificationTemplate->delete();

        return redirect()
            ->route('admin.notification-templates.index')
            ->with('success', "Template '{$name}' deleted successfully.");
    }
}
