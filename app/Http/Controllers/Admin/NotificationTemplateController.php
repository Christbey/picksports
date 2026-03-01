<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NotificationTemplateRequest;
use App\Http\Resources\Admin\NotificationTemplateAdminResource;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateDefaultService;
use App\Services\NotificationTemplatePreviewService;
use App\Services\NotificationVariableRegistry;
use App\Support\SportCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationTemplateController extends Controller
{
    private const INDEX_ROUTE = 'admin.notification-templates.index';

    public function __construct(
        private readonly NotificationTemplateDefaultService $templateDefaultService,
        private readonly NotificationTemplatePreviewService $templatePreviewService
    ) {}

    public function index(): Response
    {
        $templates = $this->resourcePayload(NotificationTemplateAdminResource::collection(
            NotificationTemplate::query()
                ->orderBy('name')
                ->get()
        ));

        return Inertia::render('Admin/NotificationTemplates/Index', [
            'templates' => $templates,
            'defaultAssignments' => $this->templateDefaultService->assignments(),
            'alertTypes' => NotificationTemplateDefaultService::ALERT_TYPES,
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

    public function ensureDailySummaryTemplate(): RedirectResponse
    {
        NotificationTemplate::query()->updateOrCreate(
            ['name' => 'Daily Betting Digest'],
            $this->dailySummaryTemplatePayload()
        );

        return $this->redirectSuccess(self::INDEX_ROUTE, "Template 'Daily Betting Digest' is available.");
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        $allowedTypes = array_keys(NotificationTemplateDefaultService::ALERT_TYPES);

        $validated = $request->validate([
            'defaults' => ['required', 'array'],
            'defaults.*' => ['nullable', 'integer', 'exists:notification_templates,id'],
        ]);

        $incomingDefaults = (array) ($validated['defaults'] ?? []);
        $defaults = [];
        foreach ($allowedTypes as $type) {
            $value = $incomingDefaults[$type] ?? null;
            $defaults[$type] = is_null($value) || $value === '' ? null : (int) $value;
        }

        $this->templateDefaultService->updateAssignments($defaults);

        return $this->redirectSuccess(self::INDEX_ROUTE, 'Default notification templates updated.');
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string'],
            'subject' => ['nullable', 'string'],
            'email_body' => ['nullable', 'string'],
            'sms_body' => ['nullable', 'string'],
            'push_title' => ['nullable', 'string'],
            'push_body' => ['nullable', 'string'],
            'context' => ['nullable', 'in:betting_value_alert,daily_betting_digest'],
            'sport' => ['nullable', 'string', 'in:all,'.implode(',', SportCatalog::ALL)],
            'date' => ['nullable', 'date'],
        ]);

        $context = $validated['context'] ?? $this->inferPreviewContext((string) ($validated['name'] ?? ''));
        $sport = $validated['sport'] ?? 'cbb';
        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : now();

        $preview = $this->templatePreviewService->buildPreview(
            user: $request->user(),
            templateFields: $validated,
            context: $context,
            sport: $sport,
            date: $date
        );

        return response()->json([
            'ok' => true,
            'preview' => $preview,
        ]);
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

    private function inferPreviewContext(string $name): string
    {
        $normalized = strtolower($name);

        if (str_contains($normalized, 'digest') || str_contains($normalized, 'daily')) {
            return 'daily_betting_digest';
        }

        return 'betting_value_alert';
    }

    /**
     * @return array<string, mixed>
     */
    private function dailySummaryTemplatePayload(): array
    {
        return [
            'description' => 'Daily summary of top betting opportunities',
            'active' => true,
            'subject' => 'Your Daily Betting Digest - {digest.bets_count} Top Opportunities for {digest.date}',
            'email_body' => 'Good morning {user.name},

Here\'s your daily betting digest for {digest.date}.

**Summary**
- Games Analyzed: {digest.total_games}
- Top Picks: {digest.bets_count}

{digest.bets_table}

{digest.empty_message}

These picks were selected using our advanced ranking algorithm based on edge value, confidence, and optimal bet sizing.

Manage your digest preferences at {system.app_url}/settings/alert-preferences

Good luck,
The {system.app_name} Team',
            'push_title' => 'Daily Digest: {digest.bets_count} picks for {digest.date}',
            'push_body' => '{digest.bets_count} top betting opportunities selected from {digest.total_games} games',
            'variables' => [
                'user.name',
                'digest.date',
                'digest.total_games',
                'digest.bets_count',
                'digest.bets_table',
                'digest.empty_message',
                'system.app_url',
                'system.app_name',
            ],
        ];
    }
}
