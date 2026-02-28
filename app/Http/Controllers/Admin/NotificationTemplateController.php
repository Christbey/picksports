<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NotificationTemplateRequest;
use App\Http\Resources\Admin\NotificationTemplateAdminResource;
use App\Models\NotificationTemplate;
use App\Services\NotificationVariableRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

class NotificationTemplateController extends Controller
{
    private const INDEX_ROUTE = 'admin.notification-templates.index';

    public function index(): Response
    {
        $templates = $this->resourcePayload(NotificationTemplateAdminResource::collection(
            NotificationTemplate::query()
                ->orderBy('name')
                ->get()
        ));

        return Inertia::render('Admin/NotificationTemplates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response
    {
        return $this->renderFormPage('Admin/NotificationTemplates/Form', 'template', null, $this->formExtras());
    }

    public function store(NotificationTemplateRequest $request): RedirectResponse
    {
        $template = NotificationTemplate::create($request->validated());

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('created', $template->name));
    }

    public function edit(NotificationTemplate $notificationTemplate): Response
    {
        return $this->renderFormPage(
            'Admin/NotificationTemplates/Form',
            'template',
            (new NotificationTemplateAdminResource($notificationTemplate))->resolve(),
            $this->formExtras()
        );
    }

    public function update(NotificationTemplateRequest $request, NotificationTemplate $notificationTemplate): RedirectResponse
    {
        $notificationTemplate->update($request->validated());

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('updated', $notificationTemplate->name));
    }

    public function destroy(NotificationTemplate $notificationTemplate): RedirectResponse
    {
        $name = $notificationTemplate->name;
        $notificationTemplate->delete();

        return $this->redirectSuccess(self::INDEX_ROUTE, $this->successMessage('deleted', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function formExtras(): array
    {
        return [
            'availableVariables' => NotificationVariableRegistry::forAdminUI(),
        ];
    }

    private function successMessage(string $action, string $name): string
    {
        return "Template '{$name}' {$action} successfully.";
    }
}
