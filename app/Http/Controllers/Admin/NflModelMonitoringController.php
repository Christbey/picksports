<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NflModelMonitoringRequest;
use App\Services\NFL\NflModelMonitoringService;
use App\Services\NFL\NflMonitorReportCache;
use Inertia\Inertia;
use Inertia\Response;

class NflModelMonitoringController extends Controller
{
    public function __invoke(NflModelMonitoringRequest $request, NflModelMonitoringService $monitoring, NflMonitorReportCache $reports): Response
    {
        $filters = $request->validated();
        $evidence = array_filter([
            'from_season' => $filters['evidence_season'] ?? null,
            'to_season' => $filters['evidence_season'] ?? null,
            'model_version' => $filters['evidence_model'] ?? null,
            'validation_from_week' => $filters['evidence_week'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $saved = $reports->read($filters['artifact'] ?? null, $evidence);
        $payload = $saved['payload'] ?? $monitoring->emptyDashboard();
        $payload['report_status'] = $saved['status'];
        $payload['signal_grades'] = fn () => $request->boolean('signals') || in_array('signal_grades', explode(',', (string) $request->header('X-Inertia-Partial-Data')), true)
            ? $reports->legacy()['grades'] : [];
        $payload['signal_report_generated_at'] = fn () => $reports->legacy()['generated_at'];

        return Inertia::render('Admin/NflModelMonitoring', $payload);
    }
}
